<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$legacyDir = USERS_PATH . '/state-v1/extensions/ArticleTopics';
ArticleTopicsStore::ensureDir($legacyDir);
ArticleTopicsStore::writeState($legacyDir, ['schema'=>1,'start_id'=>0,'cursor_id'=>80,'pending'=>[90,90],'processed_ids'=>[1,2,2],'discovery_offset'=>7]);
// Simulate a persisted v1 file (writeState deliberately writes the current schema).
file_put_contents($legacyDir . '/state.json', json_encode(['schema'=>1,'start_id'=>0,'cursor_id'=>80,'pending'=>[90,90],'processed_ids'=>[1,2,2],'discovery_offset'=>7]));
$migrated = ArticleTopicsStore::readState($legacyDir);
assertSameValue(2, $migrated['schema'], 'v1 state migrates to v2');
assertSameValue(80, $migrated['cursor_id'], 'migration retains cursor');
assertSameValue([90], $migrated['pending'], 'migration normalizes pending');
assertSameValue([1,2], $migrated['processed_ids'], 'migration retains processed baseline');
foreach (['failures','fingerprints','cache'] as $field) assertSameValue([], $migrated[$field], 'v2 default ' . $field);
$persisted = json_decode((string)file_get_contents($legacyDir . '/state.json'), true);
assertSameValue(2, $persisted['schema'], 'migration is persisted');

$normalized = ArticleTopicsStore::validateTaxonomy('[{"name":" Old topic ","enabled":true,"description":" legacy "}]');
assertSameValue(['name'=>'Old topic','enabled'=>true,'description'=>'legacy','positive_keywords'=>[],'negative_keywords'=>[],'threshold'=>2,'exclusions'=>[]], $normalized[0], 'legacy taxonomy receives defaults');
foreach ([
	'[{"name":"x","enabled":true,"description":"","positive_keywords":{"phrase":0}}]',
	'[{"name":"x","enabled":true,"description":"","negative_keywords":{"phrase":true}}]',
	'[{"name":"x","enabled":true,"description":"","threshold":-1}]',
	'[{"name":"x","enabled":true,"description":"","exclusions":["missing"]}]',
] as $invalid) assertThrows(static fn() => ArticleTopicsStore::validateTaxonomy($invalid), InvalidArgumentException::class, 'invalid taxonomy fields rejected');

$discoveryDir = USERS_PATH . '/state-v2/extensions/ArticleTopics';
FreshRSS_Factory::$entryDao->entries = ['10'=>new FreshRSS_Entry('processed','x'), '11'=>new FreshRSS_Entry('cooldown','x'), '12'=>new FreshRSS_Entry('eligible','x')];
ArticleTopicsStore::writeState($discoveryDir, ['schema'=>2,'start_id'=>0,'cursor_id'=>12,'pending'=>[],'processed_ids'=>[10],'failures'=>['11'=>['attempts'=>1,'next_retry_at'=>time()+3600]],'fingerprints'=>[],'cache'=>[]]);
ArticleTopicsStore::discoverCommitted($discoveryDir);
$state = ArticleTopicsStore::readState($discoveryDir);
assertSameValue([12], $state['pending'], 'discovery skips processed baseline and retry cooldown');
