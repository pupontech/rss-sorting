<?php
declare(strict_types=1);

require_once __DIR__ . '/ArticleTopicsClassifier.php';

/** Bounded, read-only preview and explicitly requested provider/backfill admin actions. */
final class ArticleTopicsAdmin {
	public static function diagnostics(string $extensionUserPath): array {
		$statePath = rtrim($extensionUserPath, '/\\') . '/state.json';
		$state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : [];
		if (!is_array($state)) { $state = []; }
		$failures = is_array($state['failures'] ?? null) ? $state['failures'] : [];
		$cache = is_array($state['cache'] ?? null) ? $state['cache'] : [];
		$methods = ['keywords'=>0,'hybrid'=>0,'llm'=>0];
		foreach ($cache as $result) { if (is_array($result) && isset($methods[$result['method'] ?? ''])) { $methods[$result['method']]++; } }
		if (is_array($state['metrics']['methods'] ?? null)) { foreach (array_keys($methods) as $method) { $methods[$method] = (int)($state['metrics']['methods'][$method] ?? 0); } }
		$totalMethods = array_sum($methods);
		$recent = [];
		foreach (array_slice(array_reverse($failures, true), 0, 10, true) as $id=>$failure) {
			if (!is_array($failure)) { continue; }
			$recent[] = ['entry_id'=>(string)$id,'attempts'=>(int)($failure['attempts'] ?? 0),'last_error'=>mb_substr((string)($failure['last_error'] ?? ''),0,300)];
		}
		return ['counts'=>['pending'=>count($state['pending'] ?? []),'processed'=>count($state['processed_ids'] ?? []),'failed'=>count($failures)],'methods'=>$methods,'llm_share'=>$totalMethods === 0 ? 0.0 : round(($methods['hybrid'] + $methods['llm']) * 100 / $totalMethods, 1),'recent_failures'=>$recent];
	}

	public static function healthCheck(array $config): string {
		$url = self::endpoint((string)($config['api_url'] ?? ''));
		$payload = ['model'=>(string)($config['model'] ?? ''), 'messages'=>[['role'=>'user','content'=>'Reply with OK.']], 'max_tokens'=>4];
		$response = self::http($url, $payload, $config, 3.0);
		if ($response['status'] < 200 || $response['status'] >= 300) { throw new RuntimeException('Provider health check failed (HTTP ' . $response['status'] . ').'); }
		return 'Provider responded successfully.';
	}

	public static function preview(array $config, string $title, string $body): array {
		$title = mb_substr($title, 0, 5000, 'UTF-8');
		$body = mb_substr($body, 0, min(20000, max(500,(int)($config['content_limit'] ?? 5000))), 'UTF-8');
		$taxonomy = ArticleTopicsStore::validateTaxonomy((string)($config['taxonomy_json'] ?? ''));
		$taxonomy = array_values(array_filter($taxonomy, static fn(array $topic): bool => (bool)($topic['enabled'] ?? true)));
		$previewConfig = $config;
		$previewConfig['content_limit'] = min(20000, max(500, (int)($config['content_limit'] ?? 5000)));
		$result = (new ArticleTopicsClassifier($previewConfig))->classify(['title'=>$title,'body'=>$body,'tags'=>[]], $taxonomy, microtime(true) + 3.0);
		return ['names'=>$result['names'], 'method'=>$result['method'], 'confidence'=>$result['confidence'], 'matched'=>$result['matched'], 'llm_calls'=>$result['llm_calls']];
	}

	public static function enqueueBackfill(string $extensionUserPath, array $config, string $action): string {
		if (!class_exists('ArticleTopicsBackfill')) { throw new RuntimeException('Backfill service is not installed yet.'); }
		$lockPath = rtrim($extensionUserPath, '/\\') . '/worker.lock';
		$lock = fopen($lockPath, 'c');
		if ($lock === false) { throw new RuntimeException('Could not open the per-user worker lock.'); }
		if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('A worker or backfill write is already running for this user.'); }
		try {
			$username = basename(dirname(dirname(rtrim($extensionUserPath, '/\\'))));
			$service = new ArticleTopicsBackfill($username, $config);
			if ($action === 'retry_failed') {
				$options = ['scope'=>'failed','dry_run'=>false];
			} else {
				$scope = (string)($_POST['backfill_scope'] ?? 'unread');
				$scopeMap = ['unread'=>'unread','last_7_days'=>'7d','last_30_days'=>'30d','all'=>'all','feed'=>'feed','category'=>'category','failed'=>'failed','taxonomy_changed'=>'taxonomy'];
				if (!isset($scopeMap[$scope])) { throw new InvalidArgumentException('Backfill scope is invalid.'); }
				$options = ['scope'=>$scopeMap[$scope], 'dry_run'=>isset($_POST['backfill_dry_run']), 'feed_id'=>max(0,(int)($_POST['backfill_feed_id'] ?? 0)), 'category_id'=>max(0,(int)($_POST['backfill_category_id'] ?? 0))];
				if ($scope !== 'feed') { $options['feed_id'] = null; }
				if ($scope !== 'category') { $options['category_id'] = null; }
			}
			$created = $service->create($options);
			return 'Backfill queued: ' . htmlspecialchars((string)($created['id'] ?? $created['job_id'] ?? 'job'), ENT_QUOTES, 'UTF-8');
		} finally { flock($lock, LOCK_UN); fclose($lock); }
	}

	/** @return array{status:int,body:string} */
	private static function http(string $url, array $payload, array $config, float $seconds): array {
		$headers = ['Content-Type: application/json', 'Accept: application/json'];
		if (trim((string)($config['api_key'] ?? '')) !== '') { $headers[] = 'Authorization: Bearer ' . $config['api_key']; }
		$ch = curl_init($url);
		if ($ch === false) { throw new RuntimeException('Could not initialize provider connection.'); }
		curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT_MS=>min(3000,(int)($seconds*1000)), CURLOPT_TIMEOUT_MS=>min(3000,(int)($seconds*1000)), CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXFILESIZE=>65536]);
		$body = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $ok = is_string($body); curl_close($ch);
		if (!$ok) { throw new RuntimeException('Provider connection failed or exceeded the 3 second limit.'); }
		return ['status'=>$status,'body'=>$body];
	}

	private static function endpoint(string $base): string {
		$base = rtrim(trim($base), '/');
		if ($base === '') { throw new RuntimeException('Provider URL is not configured.'); }
		if (preg_match('#/chat/completions$#i', $base)) { return $base; }
		$parts = parse_url($base);
		if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || empty($parts['host'])) { throw new RuntimeException('Configured provider URL is invalid.'); }
		return $base . '/chat/completions';
	}
}
