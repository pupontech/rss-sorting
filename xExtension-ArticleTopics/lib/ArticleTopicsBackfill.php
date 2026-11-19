<?php
declare(strict_types=1);

require_once __DIR__ . '/ArticleTopicsStore.php';
require_once __DIR__ . '/ArticleTopicsClassifier.php';
require_once __DIR__ . '/ArticleTopicsWorker.php';

/** Durable, per-user snapshot backfill. The UI may create/read jobs; only CLI calls run(). */
final class ArticleTopicsBackfill {
	private string $dir;
	private array $config;

	public function __construct(string $username, array $config) {
		if ($username === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $username) !== 1) { throw new InvalidArgumentException('Invalid FreshRSS username.'); }
		$this->dir = USERS_PATH . '/' . $username . '/extensions/ArticleTopics';
		$this->config = $config;
	}

	public function create(array $options): array {
		$scope = $options['scope'] ?? null;
		$scopes = ['unread', '7d', '30d', 'all', 'feed', 'category', 'failed', 'taxonomy'];
		if (!is_string($scope) || !in_array($scope, $scopes, true)) { throw new InvalidArgumentException('Invalid backfill scope.'); }
		$feedId = $this->positiveId($options['feed_id'] ?? null, 'feed_id', $scope === 'feed');
		$categoryId = $this->positiveId($options['category_id'] ?? null, 'category_id', $scope === 'category');
		$dryRun = $options['dry_run'] ?? false;
		if (!is_bool($dryRun)) { throw new InvalidArgumentException('dry_run must be boolean.'); }
		$dao = FreshRSS_Factory::createEntryDao();
		$highWater = $this->ids($dao, 'a', 0, FreshRSS_Entry::STATE_ALL, 0, 'DESC', 1);
		$job = [
			'id' => bin2hex(random_bytes(12)), 'scope' => $scope, 'feed_id' => $feedId, 'category_id' => $categoryId,
			'dry_run' => $dryRun, 'created_at' => gmdate(DATE_ATOM), 'status' => 'pending',
			'cursor_id' => '0', 'high_water_id' => $highWater[0] ?? '0',
			'scanned' => 0, 'labelled' => 0, 'skipped' => 0, 'failed' => 0, 'llm_calls' => 0,
			'failures' => [], 'retry_ids' => [], 'last_result' => null,
		];
		return $this->mutateJobs(static function (array &$jobs) use ($job): void { $jobs[$job['id']] = $job; return; })[$job['id']];
	}

	public function jobs(): array {
		$jobs = $this->readJobs();
		return array_values($jobs);
	}

	public function run(string $jobId, int $limit = 50, int $seconds = 20): array {
		if ($limit < 1 || $limit > 500 || $seconds < 1 || $seconds > 300) { throw new InvalidArgumentException('Backfill budgets must be 1..500 articles and 1..300 seconds.'); }
		ArticleTopicsStore::ensureDir($this->dir);
		$workerLock = fopen($this->dir . '/worker.lock', 'c');
		if ($workerLock === false || !flock($workerLock, LOCK_EX | LOCK_NB)) { return $this->getJob($jobId); }
		$deadline = microtime(true) + $seconds;
		try {
			$job = $this->getJob($jobId);
			if ($job['status'] === 'complete') { return $job; }
			if (!($this->config['enabled'] ?? false)) { throw new RuntimeException('ArticleTopics is disabled for this user.'); }
			$taxonomy = ArticleTopicsStore::validateTaxonomy((string)($this->config['taxonomy_json'] ?? ''));
			$taxonomy = array_values(array_filter($taxonomy, static fn(array $topic): bool => $topic['enabled']));
			$prefix = (string)($this->config['label_prefix'] ?? 'Topic: ');
			ArticleTopicsStore::assertNoCategoryCollisions($prefix, $taxonomy);
			if (strtolower((string)($this->config['mode'] ?? 'hybrid')) !== 'keywords' && ($this->config['api_url'] ?? '') === '') { throw new RuntimeException('Provider URL is not configured.'); }
			$classifier = new ArticleTopicsClassifier($this->config);
			$worker = new ArticleTopicsWorker($this->usernameFromDir(), $this->config, $seconds);
			$dao = FreshRSS_Factory::createEntryDao();
			$visited = 0;
			$this->updateJob($jobId, static function (array &$j): void { $j['status'] = 'running'; });
			while ($visited < $limit && microtime(true) < $deadline) {
				$job = $this->getJob($jobId);
				$retryId = null;
				foreach ($job['retry_ids'] as $queueIndex => $candidateId) {
					if ((int)($job['failures'][(string)$candidateId]['next_retry_at'] ?? 0) <= time()) {
						$retryId = $candidateId;
						break;
					}
				}
				if ($retryId !== null) {
					$id = (string)$retryId;
				} else {
					$ids = $this->nextIds($dao, $job, min(100, $limit - $visited));
					if ($ids === []) { break; }
					$id = (string)$ids[0];
				}
				$entry = $dao->searchById($id);
				$visited++;
				if ($entry === null || !$this->matches($entry, $job)) {
					$this->recordResult($jobId, $id, 'skipped', null, 0, null);
					continue;
				}
				try {
					if (class_exists('ArticleTopicsRetention') && ArticleTopicsRetention::isExpired($entry, $this->config)) {
						$this->recordResult($jobId, $id, 'skipped', [], 0, null);
						continue;
					}
					$result = $classifier->classify($classifier->inputs($entry), $taxonomy, $deadline);
					if (!$job['dry_run']) {
						$worker->applyLabels((int)$id, $result['names'], $prefix);
						$state = ArticleTopicsStore::readState($this->dir);
						unset($state['failures'][$id]);
						$state['fingerprints'][$id] = $classifier->cacheKey($classifier->inputs($entry), $taxonomy);
						$state['processed_ids'][] = (int)$id;
						ArticleTopicsStore::writeState($this->dir, $state);
					}
					$this->recordResult($jobId, $id, $result['names'] === [] ? 'skipped' : 'labelled', $result['names'], (int)$result['llm_calls'], null);
				} catch (Throwable $error) {
					$this->recordResult($jobId, $id, 'failed', null, 0, $error->getMessage());
				}
			}
			$job = $this->getJob($jobId);
			$more = $job['retry_ids'] !== [] || $this->nextIds($dao, $job, 1) !== [];
			$this->updateJob($jobId, static function (array &$j) use ($more): void { $j['status'] = $more ? 'pending' : 'complete'; });
			return $this->getJob($jobId);
		} finally {
			flock($workerLock, LOCK_UN);
			fclose($workerLock);
		}
	}

	private function usernameFromDir(): string { return basename(dirname(dirname($this->dir))); }

	private function positiveId(mixed $value, string $name, bool $required): ?int {
		if (!$required && $value === null) { return null; }
		$id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if (!is_int($id)) { throw new InvalidArgumentException($name . ' must be a positive integer for this scope.'); }
		return $id;
	}

	private function ids(object $dao, string $type, int $sourceId, int $state, int $min, string $order, int $limit, int $max = 0): array {
		$method = new ReflectionMethod($dao, 'listIdsWhere');
		if ($method->getParameters()[0]->getName() === 'type') {
			$result = $dao->listIdsWhere(type: $type, id: $sourceId, state: $state, id_min: (string)$min, id_max: (string)$max, order: $order, limit: $limit, offset: 0, secondary_sort: 'id', secondary_sort_order: $order);
		} else {
			// Compatibility with the lightweight legacy unit stub; FreshRSS 1.30.4 uses the branch above.
			$result = $dao->listIdsWhere($type, $state, (string)$min, $order, $limit, 0);
		}
		return is_array($result) ? array_values(array_filter(array_map('strval', $result), static fn(string $id): bool => $max === 0 || (int)$id <= $max)) : [];
	}

	private function nextIds(object $dao, array $job, int $limit): array {
		$cursor = (int)$job['cursor_id']; $high = (int)$job['high_water_id'];
		if ($high <= $cursor) { return []; }
		$scope = $job['scope'];
		if ($scope === 'failed') {
			$state = $this->readStateReadOnly();
			$ids = array_filter(array_keys($state['failures'] ?? []), static fn($id): bool => ctype_digit((string)$id) && (int)$id > $cursor && (int)$id <= $high);
			sort($ids, SORT_NUMERIC);
			return array_slice(array_map('strval', $ids), 0, $limit);
		}
		$type = $scope === 'feed' ? 'f' : ($scope === 'category' ? 'c' : 'a');
		$sourceId = $scope === 'feed' ? (int)$job['feed_id'] : ($scope === 'category' ? (int)$job['category_id'] : 0);
		$state = $scope === 'unread' ? FreshRSS_Entry::STATE_NOT_READ : FreshRSS_Entry::STATE_ALL;
		return $this->ids($dao, $type, $sourceId, $state, $cursor + 1, 'ASC', $limit, $high);
	}

	private function matches(FreshRSS_Entry $entry, array $job): bool {
		if (in_array($job['scope'], ['7d', '30d'], true)) {
			$days = $job['scope'] === '7d' ? 7 : 30;
			return (int)$entry->date(true) >= time() - $days * 86400;
		}
		return true;
	}

	private function readStateReadOnly(): array {
		$path = $this->dir . '/state.json';
		if (!is_file($path)) { return []; }
		$raw = file_get_contents($path);
		if ($raw === false) { return []; }
		$state = json_decode($raw, true);
		return is_array($state) ? $state : [];
	}

	private function recordResult(string $jobId, string $id, string $kind, ?array $names, int $llmCalls, ?string $error): void {
		$this->updateJob($jobId, static function (array &$j) use ($id, $kind, $names, $llmCalls, $error): void {
			$j['scanned']++;
			$j['retry_ids'] = array_values(array_filter($j['retry_ids'], static fn($retryId): bool => (string)$retryId !== $id));
			$j['llm_calls'] += $llmCalls;
			if ($kind === 'labelled') { $j['labelled']++; }
			elseif ($kind === 'skipped') { $j['skipped']++; }
			else {
				$j['failed']++;
				$previous = $j['failures'][$id] ?? ['attempts' => 0];
				$attempts = (int)$previous['attempts'] + 1;
				$j['failures'][$id] = ['attempts' => $attempts, 'last_error' => mb_substr((string)$error, 0, 1000), 'next_retry_at' => time() + min(86400, 60 * (2 ** min(10, $attempts - 1)))];
				if ($attempts < 5 && !in_array($id, $j['retry_ids'], true)) { $j['retry_ids'][] = $id; }
			}
			if ($kind !== 'failed') { unset($j['failures'][$id]); }
			$j['last_result'] = ['entry_id' => $id, 'kind' => $kind, 'labels' => $names, 'at' => gmdate(DATE_ATOM)];
			if ($id !== '') { $j['cursor_id'] = (string)max((int)$j['cursor_id'], (int)$id); }
		});
	}

	private function jobsPath(): string { return $this->dir . '/jobs.json'; }
	private function readJobs(): array {
		ArticleTopicsStore::ensureDir($this->dir);
		$lock = fopen($this->dir . '/jobs.lock', 'c');
		if ($lock === false) { throw new RuntimeException('Could not open backfill jobs lock.'); }
		flock($lock, LOCK_SH);
		try { $raw = @file_get_contents($this->jobsPath()); $jobs = $raw === false ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR); return is_array($jobs) ? $jobs : []; }
		finally { flock($lock, LOCK_UN); fclose($lock); }
	}
	private function mutateJobs(callable $mutator): array {
		ArticleTopicsStore::ensureDir($this->dir);
		$lock = fopen($this->dir . '/jobs.lock', 'c');
		if ($lock === false || !flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock backfill jobs.'); }
		try {
			$raw = @file_get_contents($this->jobsPath()); $jobs = $raw === false ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
			if (!is_array($jobs)) { $jobs = []; }
			$mutator($jobs);
			$tmp = $this->jobsPath() . '.' . bin2hex(random_bytes(6)) . '.tmp';
			$json = json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $this->jobsPath())) { @unlink($tmp); throw new RuntimeException('Could not atomically save backfill jobs.'); }
			return $jobs;
		} finally { flock($lock, LOCK_UN); fclose($lock); }
	}
	private function updateJob(string $jobId, callable $mutator): void {
		$this->mutateJobs(static function (array &$jobs) use ($jobId, $mutator): void {
			if (!isset($jobs[$jobId]) || !is_array($jobs[$jobId])) { throw new InvalidArgumentException('Unknown backfill job.'); }
			$job = $jobs[$jobId]; $mutator($job); $jobs[$jobId] = $job;
		});
	}
	private function getJob(string $jobId): array {
		$jobs = $this->readJobs();
		if (!isset($jobs[$jobId]) || !is_array($jobs[$jobId])) { throw new InvalidArgumentException('Unknown backfill job.'); }
		return $jobs[$jobId];
	}
}
