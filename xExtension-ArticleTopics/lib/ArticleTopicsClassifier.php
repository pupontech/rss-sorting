<?php
declare(strict_types=1);

/** Per-article topic classifier. Inputs and outputs are stable worker-facing contracts. */
final class ArticleTopicsClassifier {
	private const PROMPT_REVISION = 'article-topics-v2';
	private const PROFILE_FILENAME = 'keyword-profile.json';
	private ArticleTopicsLlmClient $llm;
	private array $config;
	private ?array $profile;

	public function __construct(array $config) {
		$this->config = $config;
		$this->llm = new ArticleTopicsLlmClient($config);
		$this->profile = self::loadProfile();
		if ($this->profile !== null) {
			$labels = $this->profile['topics'];
			$labels[] = ['name' => $this->profile['fallback']];
			ArticleTopicsStore::assertNoCategoryCollisions((string)($config['label_prefix'] ?? 'Topic: '), $labels);
		}
	}
	public function llmCalls(): int { return $this->llm->requestCount; }
	public function hasProfile(): bool { return $this->profile !== null; }

	/** @return list<string> */
	public function allowedNames(array $taxonomy): array {
		$names = array_column($this->normalizeTaxonomy($this->profile['topics'] ?? $taxonomy), 'name');
		if ($this->profile !== null) {
			$names[] = $this->profile['fallback'];
		} elseif ($this->singleTopic()) {
			$names[] = $this->fallbackName();
		}
		return array_values(array_unique($names));
	}

	private function singleTopic(): bool { return (int)($this->config['max_topics'] ?? 2) === 1; }
	private function fallbackName(): string { return $this->profile['fallback'] ?? 'Uncategorized'; }

	/** @return array{topics:list<array{name:string,enabled:bool,description:string,positive_keywords:array<string,int|float>,negative_keywords:list<string>,threshold:int|float,exclusions:list<string>}>,feeds:array<int,string>,focused_feeds:list<int>,fallback:string}|null */
	public static function loadProfile(): ?array {
		if (!defined('USERS_PATH') || !class_exists('Minz_User')) {
			return null;
		}
		$username = Minz_User::name();
		if ($username === null) {
			return null;
		}
		$path = USERS_PATH . '/' . $username . '/extensions/ArticleTopics/' . self::PROFILE_FILENAME;
		if (!is_file($path)) {
			return null;
		}
		if (filesize($path) > 32768 || ($raw = file_get_contents($path)) === false) {
			throw new RuntimeException('Could not read the Article Topics keyword profile.');
		}
		if (trim($raw) === '') {
			return null;
		}
		$profile = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($profile) || !is_array($profile['topics'] ?? null) || !is_array($profile['feeds'] ?? null)) {
			throw new InvalidArgumentException('The keyword profile needs topics and a feed-to-topic map.');
		}
		$topics = ArticleTopicsStore::validateTaxonomy(json_encode($profile['topics'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$activeNames = array_column(array_filter($topics, static fn(array $topic): bool => $topic['enabled']), 'name');
		$fallback = $profile['fallback'] ?? null;
		if (!is_string($fallback) || trim($fallback) !== $fallback || $fallback === '' || mb_strlen($fallback) > 100
			|| in_array(mb_strtolower($fallback), array_map('mb_strtolower', $activeNames), true)) {
			throw new InvalidArgumentException('The keyword profile needs a separate fallback label.');
		}
		foreach ($profile['feeds'] as $feedId => $name) {
			if (!is_int($feedId) || $feedId < 1 || !in_array($name, $activeNames, true)) {
				throw new InvalidArgumentException('A keyword profile feed must point to an enabled topic.');
			}
		}
		$focusedFeeds = $profile['focused_feeds'] ?? [];
		if (!is_array($focusedFeeds) || !array_is_list($focusedFeeds)) {
			throw new InvalidArgumentException('Focused feeds must be a list of feed IDs.');
		}
		foreach ($focusedFeeds as $feedId) {
			if (!is_int($feedId) || !isset($profile['feeds'][$feedId])) {
				throw new InvalidArgumentException('A focused feed needs a default topic.');
			}
		}
		return ['topics' => $topics, 'feeds' => $profile['feeds'], 'focused_feeds' => $focusedFeeds, 'fallback' => $fallback];
	}

	/** @return array{title:string,body:string,tags:list<string>,feed_id?:int} */
	public function inputs(FreshRSS_Entry $entry): array {
		$titleLimit = $this->boundedLimit($this->config['title_limit'] ?? 1000, 1000, 100, 5000);
		$bodyLimit = $this->boundedLimit($this->config['content_limit'] ?? 5000, 5000, 0, 50000);
		$tagLimit = $this->boundedLimit($this->config['tags_limit'] ?? 1000, 1000, 0, 10000);
		$title = $this->plainText($entry->title());
		$body = $this->plainText($entry->content());
		$tags = $entry->tags(); // FreshRSS 1.30.4 returns feed-supplied article tags as array<string>.
		if (!is_array($tags)) { $tags = []; }
		$tags = array_values(array_filter(array_map(fn($tag): string => $this->plainText((string)$tag), $tags), static fn(string $tag): bool => $tag !== ''));
		$tags = array_slice($tags, 0, 100);
		$inputs = ['title' => $this->slice($title, $titleLimit), 'body' => $this->slice($body, $bodyLimit), 'tags' => $this->sliceList($tags, $tagLimit)];
		if ($this->profile !== null) {
			$inputs['feed_id'] = $entry->feedId();
		}
		return $inputs;
	}

	/** Stable digest of every effective setting, input, and taxonomy value. */
	public function cacheKey(array $inputs, array $taxonomy): string {
		$config = $this->config;
		ksort($config);
		$data = ['prompt_revision' => self::PROMPT_REVISION, 'inputs' => $inputs, 'taxonomy' => $taxonomy, 'config' => $config];
		if ($this->profile !== null) {
			$data['keyword_profile'] = $this->profile;
		}
		return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
	}

	/** @return array{names:list<string>,method:string,confidence:array<string,float>,matched:array<string,list<string>>,llm_calls:int} */
	public function classify(array $inputs, array $taxonomy, float $deadline): array {
		// Accept either an absolute microtime deadline or a remaining-seconds budget (Worker contract).
		if ($deadline < 1000000000.0) { $deadline += microtime(true); }
		$mode = strtolower((string)($this->config['mode'] ?? 'hybrid'));
		if (!in_array($mode, ['keywords', 'hybrid', 'llm'], true)) { throw new InvalidArgumentException('Classifier mode must be keywords, hybrid, or llm.'); }
		$topics = $this->normalizeTaxonomy($this->profile['topics'] ?? $taxonomy);
		$feedId = is_int($inputs['feed_id'] ?? null) ? $inputs['feed_id'] : 0;
		$inputs = $this->normalizeInputs($inputs);
		$shortInputs = ['title' => $inputs['title'], 'body' => '', 'tags' => $inputs['tags']];
		$bodyInput = ['title' => '', 'body' => $inputs['body'], 'tags' => []];
		$scores = []; $matched = []; $keywordNames = []; $headlineNames = []; $weak = false;
		foreach ($topics as $i => $topic) {
			$hits = []; $headlineScore = 0;
			foreach ($topic['positive_keywords'] as $phrase => $weight) {
				if ($this->profile === null) {
					if ($this->hasPhrase($inputs, $phrase)) { $hits[$phrase] = $weight; }
				} elseif ($this->hasPhrase($shortInputs, $phrase)) {
					$hits[$phrase] = 2 * $weight;
					$headlineScore += $hits[$phrase];
				} elseif ($this->hasPhrase($bodyInput, $phrase)) {
					$hits[$phrase] = $weight;
				}
			}
			$negative = false;
			foreach ($topic['negative_keywords'] as $phrase) { if ($this->hasPhrase($inputs, $phrase)) { $negative = true; break; } }
			$score = array_sum($hits);
			if (!$negative && $score > 0 && $score < $topic['threshold']) { $weak = true; }
			$scores[$topic['name']] = $score;
			$matched[$topic['name']] = array_keys($hits);
			if (!$negative && $score >= $topic['threshold']) { $keywordNames[$topic['name']] = $i; }
			if (!$negative && $headlineScore >= $topic['threshold']) { $headlineNames[] = $topic['name']; }
		}
		$keywordConflict = $this->hasConflict(array_keys($keywordNames), $topics);
		$ambiguous = $this->singleTopic() && count($keywordNames) > 1;
		$needsLlm = $mode === 'llm' || ($mode === 'hybrid' && (count($keywordNames) === 0 || $keywordConflict || $weak || $ambiguous));
		$llmCalls = 0; $confidence = [];
		if ($mode !== 'llm' && !$needsLlm) {
			$names = $this->profile === null
				? $this->resolveNames(array_keys($keywordNames), $topics, $scores)
				: $this->profileKeywordNames($feedId, array_keys($keywordNames), $headlineNames, $topics, $scores);
			foreach ($names as $name) { $confidence[$name] = 1.0; }
			if ($names === [] && ($this->profile !== null || $this->singleTopic())) {
				$names = [$this->profile['feeds'][$feedId] ?? $this->fallbackName()];
				$confidence[$names[0]] = 0.5;
			}
			return $this->result($names, 'keywords', $confidence, $matched, 0);
		}
		$beforeCalls = $this->llmCalls();
		$response = $this->llm->classify($inputs, $this->profile['topics'] ?? $taxonomy, $deadline);
		$llmCalls = $this->llmCalls() - $beforeCalls;
		$allowed = array_fill_keys(array_column($topics, 'name'), true);
		$llmByName = [];
		foreach ($response['categories'] as $candidate) {
			$name = $candidate['name'];
			if (!isset($allowed[$name])) {
				if ($this->singleTopic()) { throw new ArticleTopicsProviderError('response', 'The AI provider selected a topic outside the configured list.'); }
				continue;
			}
			if ((float)$candidate['confidence'] < (float)($this->config['confidence_threshold'] ?? 0)) { continue; }
			$llmByName[$name] = max((float)($llmByName[$name] ?? 0.0), (float)$candidate['confidence']);
		}
		$candidates = [];
		if ($mode === 'llm' || $this->singleTopic()) { $candidates = array_keys($llmByName); }
		else { $candidates = array_values(array_unique(array_merge(array_keys($keywordNames), array_keys($llmByName)))); }
		foreach ($llmByName as $name => $value) { $confidence[$name] = $value; }
		foreach ($llmByName as $name => $value) { if ($mode === 'llm' || $this->singleTopic() || !isset($keywordNames[$name])) { $scores[$name] = $value; } }
		$names = $this->resolveNames($candidates, $topics, $scores);
		if ($names === [] && $this->profile === null && is_string($this->config['fallback_topic'] ?? null) && $this->config['fallback_topic'] !== '') {
			foreach ($topics as $topic) { if ($topic['name'] === $this->config['fallback_topic']) { $names = [$topic['name']]; break; } }
		}
		if ($names === [] && ($this->profile !== null || $this->singleTopic())) {
			$names = [$this->singleTopic() ? $this->fallbackName() : ($this->profile['feeds'][$feedId] ?? $this->fallbackName())];
		}
		foreach ($names as $name) { $confidence[$name] ??= 0.5; }
		return $this->result($names, $mode === 'llm' ? 'llm' : 'hybrid', $confidence, $matched, $llmCalls);
	}

	private function result(array $names, string $method, array $confidence, array $matched, int $calls): array {
		return ['names' => array_values($names), 'method' => $method, 'confidence' => $confidence, 'matched' => $matched, 'llm_calls' => $calls];
	}

	private function profileKeywordNames(int $feedId, array $candidates, array $headlineNames, array $topics, array $scores): array {
		$names = $this->resolveNames($headlineNames ?: $candidates, $topics, $scores);
		if (in_array($feedId, $this->profile['focused_feeds'], true)) {
			$main = $this->profile['feeds'][$feedId];
			$second = array_values(array_diff($headlineNames === [] ? [] : $names, [$main]));
			$limit = $this->boundedLimit($this->config['max_topics'] ?? 2, 2, 1, 100);
			return array_slice([$main, ...$second], 0, $limit);
		}
		return $headlineNames === [] ? array_slice($names, 0, 1) : $names;
	}

	private function normalizeTaxonomy(array $taxonomy): array {
		$out = []; $seen = [];
		foreach ($taxonomy as $topic) {
			if (!is_array($topic) || !is_string($topic['name'] ?? null) || ($topic['enabled'] ?? true) === false) { continue; }
			$name = trim($topic['name']); if ($name === '' || isset($seen[mb_strtolower($name, 'UTF-8')])) { continue; }
			$seen[mb_strtolower($name, 'UTF-8')] = true;
			$positive = $topic['positive_keywords'] ?? [];
			if (!is_array($positive)) { $positive = []; }
			$weights = [];
			foreach ($positive as $phrase => $weight) {
				if (!is_string($phrase) || trim($phrase) === '' || (!is_int($weight) && !is_float($weight)) || !is_finite((float)$weight) || $weight <= 0) { continue; }
				$weights[trim($phrase)] = (float)$weight;
			}
			$negative = $topic['negative_keywords'] ?? [];
			if (!is_array($negative)) { $negative = []; }
			$negative = array_values(array_filter(array_map(static fn($p): string => is_string($p) ? trim($p) : '', $negative), static fn(string $p): bool => $p !== ''));
			$threshold = $topic['threshold'] ?? 2;
			if ((!is_int($threshold) && !is_float($threshold)) || !is_finite((float)$threshold)) { $threshold = 2; }
			$exclusions = $topic['exclusions'] ?? [];
			if (!is_array($exclusions)) { $exclusions = []; }
			$out[] = ['name' => $name, 'description' => (string)($topic['description'] ?? ''), 'positive_keywords' => $weights, 'negative_keywords' => $negative, 'threshold' => (float)$threshold, 'exclusions' => array_values(array_filter($exclusions, 'is_string'))];
		}
		return $out;
	}

	private function normalizeInputs(array $inputs): array {
		$title = is_string($inputs['title'] ?? null) ? $inputs['title'] : '';
		$body = is_string($inputs['body'] ?? null) ? $inputs['body'] : '';
		$tags = is_array($inputs['tags'] ?? null) ? $inputs['tags'] : [];
		$tags = array_values(array_filter($tags, 'is_string'));
		return ['title' => $title, 'body' => $body, 'tags' => $tags];
	}

	private function hasPhrase(array $inputs, string $phrase): bool {
		$phrase = $this->fold($phrase);
		if ($phrase === '') { return false; }
		$pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}_])/u';
		foreach ([$inputs['title'], $inputs['body'], implode(' ', $inputs['tags'])] as $text) {
			if (preg_match($pattern, $this->fold($text)) === 1) { return true; }
		}
		return false;
	}

	private function fold(string $text): string { return mb_strtolower($text, 'UTF-8'); }

	private function hasConflict(array $names, array $topics): bool {
		if (count($names) < 2) { return false; }
		$map = []; foreach ($topics as $topic) { $map[mb_strtolower($topic['name'], 'UTF-8')] = $topic; }
		foreach ($names as $name) { foreach ($map[mb_strtolower($name, 'UTF-8')]['exclusions'] as $excluded) { if (in_array(mb_strtolower($excluded, 'UTF-8'), array_map(static fn($n): string => mb_strtolower($n, 'UTF-8'), $names), true)) { return true; } } }
		return false;
	}

	private function resolveNames(array $candidates, array $topics, array $scores): array {
		$byName = []; foreach ($topics as $topic) { $byName[$topic['name']] = $topic; }
		$candidates = array_values(array_unique(array_filter($candidates, static fn($name): bool => is_string($name) && isset($byName[$name]))));
		$filtered = [];
		foreach ($candidates as $name) {
			$excludedByWinner = false;
			foreach ($candidates as $other) {
				$excludedNames = array_map(static fn(string $value): string => mb_strtolower($value, 'UTF-8'), $byName[$other]['exclusions']);
				if ($other === $name || !in_array(mb_strtolower($name, 'UTF-8'), $excludedNames, true)) { continue; }
				$a = (float)($scores[$other] ?? 0); $b = (float)($scores[$name] ?? 0);
				if ($a > $b || ($a === $b && array_search($other, array_keys($byName), true) < array_search($name, array_keys($byName), true))) { $excludedByWinner = true; break; }
			}
			if (!$excludedByWinner) { $filtered[] = $name; }
		}
		usort($filtered, static function (string $a, string $b) use ($scores, $byName): int { $diff = (float)($scores[$b] ?? 0) <=> (float)($scores[$a] ?? 0); return $diff !== 0 ? $diff : (array_search($a, array_keys($byName), true) <=> array_search($b, array_keys($byName), true)); });
		$max = $this->boundedLimit($this->config['max_topics'] ?? 2, 2, 1, 100);
		return array_slice($filtered, 0, $max);
	}

	private function plainText(string $text): string { return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')); }
	private function slice(string $text, int $limit): string { return $limit === 0 ? '' : mb_substr($text, 0, $limit, 'UTF-8'); }
	private function sliceList(array $items, int $limit): array { $out = []; $left = $limit; foreach ($items as $item) { if ($left <= 0) { break; } $take = mb_substr($item, 0, $left, 'UTF-8'); if ($take !== '') { $out[] = $take; $left -= mb_strlen($take, 'UTF-8'); } } return $out; }
	private function boundedLimit(mixed $value, int $default, int $min, int $max): int { if (!is_numeric($value)) { return $default; } return max($min, min($max, (int)$value)); }
}
