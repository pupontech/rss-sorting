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
