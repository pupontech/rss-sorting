<?php
declare(strict_types=1);

if (!class_exists('Minz_Extension')) {
	abstract class Minz_Extension {
		private array $testConfig = [];
		protected function getUserConfigurationValue(string $key, mixed $default = null): mixed { return $this->testConfig[$key] ?? $default; }
		protected function setUserConfigurationValue(string $key, mixed $value): void { $this->testConfig[$key] = $value; }
		protected function getExtensionUserPath(): string { return sys_get_temp_dir() . '/at-admin-test/extensions/ArticleTopics'; }
	}
}
require_once dirname(__DIR__, 2) . '/xExtension-ArticleTopics/extension.php';

$safe = ArticleTopicsExtension::configurationForAdmin(['api_key'=>'secret-test-value','api_url'=>'http://127.0.0.1:9000/v1','model'=>'mock']);
assertSameValue(true, $safe['api_key_configured'], 'admin config reports configured key');
assertSameValue(false, array_key_exists('api_key', $safe), 'admin config never exposes provider key');
assertSameValue(false, str_contains(json_encode($safe) ?: '', 'secret-test-value'), 'admin config serialization never contains provider key');

$topic = ['name'=>'Video Games','enabled'=>true,'description'=>'','positive_keywords'=>['zelda'=>2],'negative_keywords'=>[],'threshold'=>2,'exclusions'=>[]];
$preview = ArticleTopicsAdmin::preview(['mode'=>'keywords','max_topics'=>2,'content_limit'=>5000,'taxonomy_json'=>json_encode([$topic])], 'Nintendo announces Zelda game', '');
assertSameValue(['Video Games'], $preview['names'], 'preview classifies supplied text');
assertSameValue('keywords', $preview['method'], 'preview reports classification method');
assertSameValue(['zelda'], $preview['matched']['Video Games'], 'preview reports matched keyword');
assertSameValue(0, $preview['llm_calls'], 'keyword preview makes no provider call');

$dir = USERS_PATH . '/notices/extensions/ArticleTopics';
ArticleTopicsStore::writeState($dir, ['pending'=>[4], 'processed_ids'=>[3], 'failures'=>[
	'1'=>['reason'=>'quota','first_at'=>200,'attempts'=>1,'next_retry_at'=>999,'exhausted'=>false],
	'2'=>['reason'=>'quota','first_at'=>100,'attempts'=>2,'next_retry_at'=>999,'exhausted'=>false],
	'3'=>['reason'=>'temporary','first_at'=>300,'attempts'=>2,'next_retry_at'=>999,'exhausted'=>false],
	'4'=>['reason'=>'temporary','first_at'=>250,'attempts'=>5,'next_retry_at'=>PHP_INT_MAX,'exhausted'=>true],
]]);
$notices = ArticleTopicsAdmin::failureNotices($dir);
assertSameValue(2, $notices['quota']['count'], 'quota notice is immediate and groups affected articles');
assertSameValue(100, $notices['quota']['first_at'], 'notice uses the earliest failure');
assertSameValue(1, $notices['temporary']['count'], 'transient failure is visible only after retries');
assertSameValue(4, ArticleTopicsStore::retryLiveFailures($dir));
$retry = ArticleTopicsStore::readState($dir);
assertSameValue([4,1,2,3], $retry['pending']);
assertSameValue([], $retry['processed_ids']);
assertSameValue(0, $retry['failures'][4]['next_retry_at']);
