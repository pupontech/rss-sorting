<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$worker = new ArticleTopicsWorker('reliability', [
	'enabled' => true,
	'api_url' => 'http://127.0.0.1:1',
	'max_attempts' => 5,
	'retry_base_seconds' => 60,
]);
$record = new ReflectionMethod(ArticleTopicsWorker::class, 'recordFailure');
$record->setAccessible(true);
$state = ['failures' => []];
$firstAt = time();
assertSameValue(1, $record->invokeArgs($worker, [&$state, 41, new RuntimeException('temporary outage')]), 'first failure attempt');
assertSameValue(1, $state['failures']['41']['attempts'], 'failure count persisted in state');
assertSameValue(true, $state['failures']['41']['next_retry_at'] >= $firstAt + 60, 'first retry uses configured base delay');
assertSameValue('temporary outage', $state['failures']['41']['last_error'], 'failure diagnostic persisted');

for ($attempt = 2; $attempt <= 5; $attempt++) {
	assertSameValue($attempt, $record->invokeArgs($worker, [&$state, 41, new RuntimeException('temporary outage')]), 'retry attempt count');
}
assertSameValue(true, $state['failures']['41']['next_retry_at'] > time() + 86400, 'capped failure is durably ineligible for retry');

$dir = USERS_PATH . '/reliability/extensions/ArticleTopics';
ArticleTopicsStore::ensureDir($dir);
file_put_contents($dir . '/state.json', json_encode([
	'schema' => 1,
	'start_id' => 0,
	'cursor_id' => 17,
	'pending' => [18],
	'processed_ids' => [17],
]));
$migrated = ArticleTopicsStore::readState($dir);
assertSameValue(ArticleTopicsStore::SCHEMA, $migrated['schema'], 'v1 state migrates to current schema');
assertSameValue([], $migrated['failures'], 'migration initializes failure state');
assertSameValue([], $migrated['fingerprints'], 'migration initializes fingerprints');
assertSameValue([], $migrated['cache'], 'migration initializes cache');
assertSameValue([18], $migrated['pending'], 'migration preserves queued work');

$migrated['failures'] = $state['failures'];
ArticleTopicsStore::writeState($dir, $migrated);
$reloaded = ArticleTopicsStore::readState($dir);
assertSameValue($state['failures'], $reloaded['failures'], 'retry record survives durable state round trip');
