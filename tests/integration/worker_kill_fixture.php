<?php
declare(strict_types=1);
$repo = dirname(__DIR__, 2);
$root = $repo . '/.upstream/FreshRSS';
require $root . '/cli/_cli.php';
$user = getenv('M2_TEST_USER') ?: '';
if (!preg_match('/^m2test[0-9]+$/', $user)) { throw new RuntimeException('Disposable test user required.'); }
cliInitUser($user);
require_once $root . '/extensions/xExtension-ArticleTopics/lib/ArticleTopicsStore.php';
require_once $root . '/extensions/xExtension-ArticleTopics/lib/ArticleTopicsWorker.php';
$config = FreshRSS_Context::userConf()->extensions['ArticleTopics'] ?? [];
$config['enabled'] = true;
$config['mode'] = 'llm';
$config['api_url'] = 'http://127.0.0.1:8081/v1';
$config['max_attempts'] = 5;
$dir = USERS_PATH . '/' . $user . '/extensions/ArticleTopics';
$state = ArticleTopicsStore::readState($dir);
$state['start_id'] = 0;
$state['cursor_id'] = 0;
$state['pending'] = [];
$state['processed_ids'] = [];
$state['failures'] = [];
$state['fingerprints'] = [];
ArticleTopicsStore::writeState($dir, $state);
exit((new ArticleTopicsWorker($user, $config, 60))->run(50));
