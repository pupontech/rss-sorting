#!/usr/bin/env php
<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/cli/_cli.php';
require_once dirname(__DIR__) . '/lib/ArticleTopicsStore.php';
require_once dirname(__DIR__) . '/lib/ArticleTopicsBackfill.php';

$options = new class extends CliOptionsParser {
	public string $user;
	public string $job = '';
	public string $scope = '';
	public string $feedId = '';
	public string $categoryId = '';
	public int $limit = 50;
	public int $seconds = 20;
	public bool $dryRun = false;
	public bool $next = false;
	public function __construct() {
		$this->addRequiredOption('user', new CliOption('user'));
		$this->addOption('job', new CliOption('job'));
		$this->addOption('scope', new CliOption('scope'));
		$this->addOption('feedId', new CliOption('feed-id'));
		$this->addOption('categoryId', new CliOption('category-id'));
		$this->addOption('limit', (new CliOption('limit'))->typeOfInt());
		$this->addOption('seconds', (new CliOption('seconds'))->typeOfInt());
		$this->addOption('dryRun', (new CliOption('dry-run'))->withValueNone());
		$this->addOption('next', (new CliOption('next'))->withValueNone());
		parent::__construct();
	}
};
if (!empty($options->errors)) { fail(array_shift($options->errors) . "\n" . $options->usage); }
$username = cliInitUser($options->user);
$extensions = FreshRSS_Context::userConf()->extensions ?? [];
$config = is_array($extensions) ? ($extensions['ArticleTopics'] ?? []) : [];
if (!is_array($config)) { $config = []; }
$engine = new ArticleTopicsBackfill($username, $config);
$limit = filter_var($options->limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
$seconds = filter_var($options->seconds, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
if (!is_int($limit) || !is_int($seconds)) { fail('Invalid --limit or --seconds budget.'); }
try {
	if ($options->next) {
		foreach ($engine->jobs() as $queuedJob) { if (($queuedJob['status'] ?? '') !== 'complete') { $options->job = $queuedJob['id']; break; } }
		if ($options->job === '') { fwrite(STDOUT, "No queued backfill jobs.\n"); exit(0); }
	}
	if ($options->job !== '') {
		$jobId = $options->job;
	} else {
		if ($options->scope === '') { fail('Provide --job=ID to resume, or --scope=unread|7d|30d|all|feed|category|failed|taxonomy to create a job.'); }
		$job = $engine->create([
			'scope' => $options->scope,
			'feed_id' => $options->feedId === '' ? null : $options->feedId,
			'category_id' => $options->categoryId === '' ? null : $options->categoryId,
			'dry_run' => $options->dryRun,
		]);
		$jobId = $job['id'];
	}
	$result = $engine->run($jobId, $limit, $seconds);
	fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
	fail('Backfill failed: ' . $error->getMessage());
}
