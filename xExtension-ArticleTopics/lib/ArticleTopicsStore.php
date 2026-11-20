<?php
declare(strict_types=1);

final class ArticleTopicsStore {
	public const SCHEMA = 2;

	public static function defaultTaxonomyJson(): string {
		$topics = [
			['Video Games', 'Games, game studios, consoles, and gaming culture.', ['video game'=>3, 'nintendo'=>3, 'zelda'=>4, 'game developer'=>3, 'steam deck'=>3, 'switch 2'=>3], ['steam turbine','light switch']],
			['Technology', 'Consumer and enterprise technology broadly.', ['macos'=>3, 'software update'=>2, 'technology'=>1, 'apple releases'=>2, 'windows vulnerability'=>3, 'windows security'=>3], ['apple farmers','apple orchard']],
			['Artificial Intelligence', 'Machine learning, generative AI, and AI products.', ['artificial intelligence'=>3, ' ai '=>2, 'machine learning'=>3, 'ai assistant'=>3, 'generative ai'=>3], []],
			['Cybersecurity', 'Security vulnerabilities, attacks, and defense.', ['vulnerability'=>3, 'critical security'=>3, 'cybersecurity'=>3, 'security patch'=>3, 'patched'=>1], []],
			['Software Development', 'Programming, software engineering, and developer tools.', ['software development'=>3, 'open-source software'=>2, 'coding assistant'=>2, 'programming'=>3, 'developer tools'=>2, 'open-source nas'=>3], []],
			['Hardware', 'Computers, devices, processors, and networking equipment.', ['10gbe'=>3, 'network switch'=>3, 'nas build'=>2, 'hardware'=>2, 'performance benchmarks'=>1, 'steam deck'=>2], ['nintendo switch']],
			['Self-Hosting', 'Self-hosted services, homelabs, NAS, and open source infrastructure.', ['self-hosted'=>3, 'homelab'=>3, 'nas'=>2, 'open-source nas'=>4, 'network attached storage'=>3], []],
			['Finance', 'Markets, investing, banking, and economics.', ['financial markets'=>3, 'inflation'=>2, 'stock market'=>3, 'banking'=>2, 'investing'=>2], []],
			['Business', 'Companies, management, and business news.', ['layoffs'=>2, 'revenue'=>2, 'acquisition'=>2, 'business'=>1, 'company'=>1], []],
			['Science', 'Scientific research and discoveries.', ['astrophysics'=>4, 'scientific discovery'=>3, 'researchers find'=>2, 'turbine technology developed'=>2, 'drought research'=>1], []],
			['Politics', 'Government, elections, and public policy.', ['election'=>3, 'government'=>2, 'public policy'=>3, 'parliament'=>2], []],
			['Entertainment', 'Film, television, music, books, and celebrity culture.', ['film'=>2, 'television'=>2, 'album'=>2, 'author'=>1], []],
			['Health', 'Medicine, public health, and wellness research.', ['public health'=>3, 'medical'=>2, 'clinical trial'=>3, 'healthcare'=>2], []],
		];
		$data = [];
		foreach ($topics as [$name, $description, $positive, $negative]) {
			$data[] = ['name'=>$name, 'enabled'=>true, 'description'=>$description, 'positive_keywords'=>$positive, 'negative_keywords'=>$negative, 'threshold'=>2, 'exclusions'=>[]];
		}
		return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** @return list<array{name:string,enabled:bool,description:string,positive_keywords:array<string,int|float>,negative_keywords:list<string>,threshold:int|float,exclusions:list<string>}> */
	public static function validateTaxonomy(string $json): array {
		$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($data) || !array_is_list($data) || count($data) < 1 || count($data) > 100) throw new InvalidArgumentException('Taxonomy must be a JSON list with 1 to 100 topics.');
		$result = []; $seen = [];
		foreach ($data as $topic) {
			if (!is_array($topic) || !is_string($topic['name'] ?? null) || !is_string($topic['description'] ?? null) || !is_bool($topic['enabled'] ?? null)) throw new InvalidArgumentException('Every topic needs string name/description and boolean enabled fields.');
			$name = trim($topic['name']); $description = trim($topic['description']);
			if ($name === '' || mb_strlen($name) > 100 || mb_strlen($description) > 1000) throw new InvalidArgumentException('Topic names must be 1ג€“100 characters and descriptions at most 1000 characters.');
			$key = mb_strtolower($name); if (isset($seen[$key])) throw new InvalidArgumentException('Topic names must be unique, ignoring case.'); $seen[$key] = true;
			$positive = $topic['positive_keywords'] ?? []; $negative = $topic['negative_keywords'] ?? []; $threshold = $topic['threshold'] ?? 2; $exclusions = $topic['exclusions'] ?? [];
			if (!is_array($positive) || array_is_list($positive) && $positive !== []) throw new InvalidArgumentException('positive_keywords must be an associative phrase-to-weight object.');
			$normalizedPositive = [];
			foreach ($positive as $phrase => $weight) {
				if (!is_string($phrase) || trim($phrase) === '' || mb_strlen($phrase) > 200 || (!is_int($weight) && !is_float($weight)) || !is_finite((float)$weight) || $weight <= 0 || $weight > 100) throw new InvalidArgumentException('Positive keyword phrases need a positive numeric weight no greater than 100.');
				$normalizedPositive[trim($phrase)] = $weight;
			}
			if (!is_array($negative) || !array_is_list($negative)) throw new InvalidArgumentException('negative_keywords must be a list of phrases.');
			foreach ($negative as $phrase) if (!is_string($phrase) || trim($phrase) === '' || mb_strlen($phrase) > 200) throw new InvalidArgumentException('Negative keyword phrases must be non-empty strings up to 200 characters.');
			if ((!is_int($threshold) && !is_float($threshold)) || !is_finite((float)$threshold) || $threshold < 0 || $threshold > 1000) throw new InvalidArgumentException('threshold must be a non-negative number no greater than 1000.');
			if (!is_array($exclusions) || !array_is_list($exclusions)) throw new InvalidArgumentException('exclusions must be a list of topic names.');
			foreach ($exclusions as $excluded) if (!is_string($excluded) || trim($excluded) === '' || mb_strlen($excluded) > 100) throw new InvalidArgumentException('Exclusions must be non-empty topic names up to 100 characters.');
			$result[] = ['name'=>$name, 'enabled'=>$topic['enabled'], 'description'=>$description, 'positive_keywords'=>$normalizedPositive, 'negative_keywords'=>array_map('trim', $negative), 'threshold'=>$threshold, 'exclusions'=>array_map('trim', $exclusions)];
		}
		foreach ($result as $topic) foreach ($topic['exclusions'] as $excluded) if (!isset($seen[mb_strtolower($excluded)])) throw new InvalidArgumentException('Exclusions must refer to a topic in the taxonomy: ' . $excluded);
		if (strlen((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 12000) throw new InvalidArgumentException('Taxonomy JSON must not exceed 12,000 encoded bytes.');
		return $result;
	}

	public static function assertNoCategoryCollisions(string $prefix, array $taxonomy): void {
		$categoryDao = FreshRSS_Factory::createCategoryDao(); $categories = $categoryDao->listCategories(); $names = [];
		foreach ($categories as $category) { $name = is_object($category) ? $category->name() : (string)($category['name'] ?? ''); $names[mb_strtolower(htmlspecialchars_decode($name, ENT_QUOTES))] = true; }
		foreach ($taxonomy as $topic) { $label = trim($prefix . $topic['name']); if (mb_strcut($label, 0, FreshRSS_DatabaseDAO::LENGTH_INDEX_UNICODE, 'UTF-8') !== $label) throw new InvalidArgumentException('Topic label is too long for FreshRSS: ' . $label); $lookup = htmlspecialchars($label, ENT_COMPAT, 'UTF-8'); if ($categoryDao->searchByName($lookup) !== null || $categoryDao->searchByName($label) !== null || isset($names[mb_strtolower($label)])) throw new InvalidArgumentException('Topic label conflicts with an existing feed category: ' . $label); }
	}

	public static function enableAtHighWater(string $dir): void {
		self::ensureDir($dir); $lock = fopen($dir . '/worker.lock', 'c');
		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Worker is active; retry enabling classification after it finishes.');
		try { self::writeState($dir, self::stateForNewArrivals()); }
		finally { flock($lock, LOCK_UN); fclose($lock); }
	}

	/** Capture the initial article set while the caller holds the worker lock. */
	public static function stateForNewArrivals(): array {
		$existing = [];
		$minimum = '0';
		while (true) {
			$ids = FreshRSS_Factory::createEntryDao()->listIdsWhere('a', state: FreshRSS_Entry::STATE_ALL,
				id_min: $minimum, order: 'ASC', limit: 1000) ?? [];
			foreach ($ids as $id) { $existing[] = (int)$id; }
			if (count($ids) < 1000) { break; }
			$minimum = (string)((int)end($ids) + 1);
		}
		// Keep scanning low IDs so a delayed feed commit can still be discovered.
		return self::newState($existing === [] ? 0 : max($existing), $existing);
	}

	public static function retryLiveFailures(string $dir): int {
		self::ensureDir($dir);
		$lock = fopen($dir . '/worker.lock', 'c');
		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
			throw new RuntimeException('Worker is active. Try again when it finishes.');
		}
		try {
			$state = self::readState($dir);
			$retryIds = [];
			foreach ($state['failures'] as $id => &$failure) {
				$retryIds[] = (int)$id;
				$failure['attempts'] = 0;
				$failure['next_retry_at'] = 0;
				$failure['exhausted'] = false;
			}
			unset($failure);
			$state['pending'] = array_values(array_unique(array_merge($state['pending'], $retryIds)));
			$state['processed_ids'] = array_values(array_diff($state['processed_ids'], $retryIds));
			self::writeState($dir, $state);
			if ($retryIds !== [] && function_exists('invalidateHttpCache')) { invalidateHttpCache(); }
			return count($retryIds);
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	public static function discoverCommitted(string $dir): void {
		self::ensureDir($dir); $state=self::readState($dir); $pending=[]; $processed=array_fill_keys(array_map('strval',$state['processed_ids']),true); $now=time();
		foreach ($state['pending'] as $id) { $id=(string)$id; $failure=$state['failures'][$id] ?? null; if (!isset($processed[$id]) && (!is_array($failure) || (int)($failure['next_retry_at'] ?? 0) <= $now)) $pending[$id]=true; }
		$offset=max(0,(int)($state['discovery_offset'] ?? 0)); $pages=0;
		do { $ids=FreshRSS_Factory::createEntryDao()->listIdsWhere('a', state:FreshRSS_Entry::STATE_ALL, id_min:(string)max(1,(int)$state['start_id']+1), order:'ASC', limit:1000, offset:$offset) ?? []; foreach ($ids as $id) { $id=(string)$id; if (isset($processed[$id])) continue; $failure=$state['failures'][$id] ?? null; if (is_array($failure) && (int)($failure['next_retry_at'] ?? 0) > $now) continue; $pending[$id]=true; } $offset+=count($ids); $pages++; } while (count($ids)===1000 && $pages<2);
		$state['discovery_offset'] = count($ids) < 1000 ? 0 : $offset;
		$state['pending'] = array_map('intval', array_keys($pending));
		sort($state['pending'], SORT_NUMERIC);
		if (isset($state['trial_started_at'])) {
			$state['trial_articles'] = array_slice(array_values(array_unique(array_merge($state['trial_articles'] ?? [], $state['pending']))), 0, 50);
		}
		self::writeState($dir, $state);
	}

	private static function newState(int $cursor=0,array $processed=[]): array { return ['schema'=>self::SCHEMA,'start_id'=>0,'cursor_id'=>$cursor,'pending'=>[],'processed_ids'=>array_values(array_unique(array_map('intval',$processed))),'discovery_offset'=>0,'failures'=>[],'fingerprints'=>[],'cache'=>[]]; }
	public static function readState(string $dir): array {
		$path=$dir.'/state.json'; if (!is_file($path)) return self::newState();
		$data=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR); if (!is_array($data) || !in_array($data['schema'] ?? null,[1,self::SCHEMA],true)) throw new RuntimeException('Unsupported or corrupt ArticleTopics state schema.');
		$schema=(int)$data['schema']; foreach (['start_id'=>0,'cursor_id'=>0,'discovery_offset'=>0] as $key=>$default) { if (!isset($data[$key]) || !is_numeric($data[$key]) || (int)$data[$key]<0) $data[$key]=$default; else $data[$key]=(int)$data[$key]; }
		foreach (['pending','processed_ids'] as $key) { if (!is_array($data[$key] ?? null)) $data[$key]=[]; $data[$key]=array_values(array_unique(array_map('intval',array_filter($data[$key],static fn($v)=>is_numeric($v)&&(int)$v>=0)))); }
		foreach (['failures','fingerprints','cache'] as $key) if (!is_array($data[$key] ?? null)) $data[$key]=[];
		$data['schema']=self::SCHEMA; if ($schema===1) self::writeState($dir,$data); return $data;
	}
	public static function writeState(string $dir,array $state): void { self::ensureDir($dir); $state['schema']=self::SCHEMA; $tmp=$dir.'/state.json.tmp'; $json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); $handle=fopen($tmp,'c'); if ($handle===false) throw new RuntimeException('Could not persist ArticleTopics state.'); try { if (!flock($handle,LOCK_EX) || !ftruncate($handle,0) || fwrite($handle,$json)!==strlen($json) || !fflush($handle)) throw new RuntimeException('Could not persist ArticleTopics state.'); if (function_exists('fsync')) fsync($handle); } finally { flock($handle,LOCK_UN); fclose($handle); } if (!rename($tmp,$dir.'/state.json')) throw new RuntimeException('Could not persist ArticleTopics state.'); }
	public static function ensureDir(string $dir): void { if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Cannot create per-user ArticleTopics storage.'); }
}
