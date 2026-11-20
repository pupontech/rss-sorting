#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ArticleTopicsStore.php';
require_once dirname(__DIR__) . '/lib/ArticleTopicsWorker.php';

$options = new class extends CliOptionsParser {
	public string $user;
	public string $limit = '';
	public function __construct() {
		$this->addRequiredOption('user', new CliOption('user'));
		$this->addOption('limit', new CliOption('limit'));
		parent::__construct();
	}
};
if (!empty($options->errors)) { fail(array_shift($options->errors) . "\n" . $options->usage); }
if (!ArticleTopicsAccess::allows($options->user)) { fail('Article Topics is unavailable for this account.'); }
$username = cliInitUser($options->user);
$extensions = FreshRSS_Context::userConf()->extensions ?? [];
$config = is_array($extensions) ? ($extensions['ArticleTopics'] ?? []) : [];
if (!is_array($config)) { $config = []; }
$limit = filter_var($options->limit === '' ? ($config['worker_limit'] ?? 50) : $options->limit, FILTER_VALIDATE_INT, ['options' => ['min_range'=>1,'max_range'=>500]]);
if (!is_int($limit)) { fail('Invalid --limit; expected an integer from 1 to 500.'); }
$seconds = max(1,min(300,(int)($config['worker_seconds'] ?? 20)));
$exit = (new ArticleTopicsWorker($username, $config, $seconds))->run($limit);
exit($exit);
