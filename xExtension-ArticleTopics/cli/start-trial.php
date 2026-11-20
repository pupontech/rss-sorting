#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ArticleTopicsStore.php';
require_once dirname(__DIR__) . '/lib/ArticleTopicsBackfill.php';

$options = new class extends CliOptionsParser {
	public string $user;
	public string $apiUrl = 'https://api.openai.com/v1';
	public string $model = 'gpt-6-luna';
	public function __construct() {
		$this->addRequiredOption('user', new CliOption('user'));
		$this->addOption('apiUrl', new CliOption('api-url'));
		$this->addOption('model', new CliOption('model'));
		parent::__construct();
	}
};
if ($options->errors !== []) { fail(array_shift($options->errors) . "\n" . $options->usage); }
if (!ArticleTopicsAccess::allows($options->user)) { fail('Article Topics is unavailable for this account.'); }

$apiKey = trim((string)fgets(STDIN));
$url = rtrim($options->apiUrl, '/');
if ($apiKey === '' || $options->model === '' || filter_var($url, FILTER_VALIDATE_URL) === false
	|| !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
	fail('Supply the provider API key on stdin and a valid provider URL and model.');
}

$username = cliInitUser($options->user);
$directory = USERS_PATH . '/' . $username . '/extensions/ArticleTopics';
$profilePath = $directory . '/keyword-profile.json';
$statePath = $directory . '/state.json';
$configuration = FreshRSS_Context::userConf();
$previousConfiguration = $configuration->extensions ?? [];
$config = $previousConfiguration['ArticleTopics'] ?? [];
$prefix = (string)($config['label_prefix'] ?? 'Topic: ');
$profile = (string)file_get_contents(dirname(__DIR__) . '/profiles/one-topic.json');
$decoded = json_decode($profile, true, 32, JSON_THROW_ON_ERROR);
$topics = ArticleTopicsStore::validateTaxonomy(json_encode($decoded['topics'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
ArticleTopicsStore::assertNoCategoryCollisions($prefix, [...$topics, ['name' => $decoded['fallback']]]);

ArticleTopicsStore::ensureDir($directory);
$lock = fopen($directory . '/worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { fail('Worker is active. Try again when it finishes.'); }

$previousFiles = [];
$changedFiles = [];
$configurationChanged = false;
try {
	if (isset(ArticleTopicsStore::readState($directory)['trial_started_at'])) { throw new RuntimeException('A new-arrivals trial has already started.'); }
	foreach ((new ArticleTopicsBackfill($username, $config))->jobs() as $job) {
		if ($job['status'] !== 'complete') { throw new RuntimeException('Finish the existing backfill before starting the trial.'); }
	}
	foreach ([$profilePath, $statePath] as $path) {
		$previousFiles[$path] = is_file($path) ? file_get_contents($path) : null;
		if ($previousFiles[$path] === false) { throw new RuntimeException('Could not read the existing profile or worker state.'); }
	}
	$state = ArticleTopicsStore::stateForNewArrivals();
	$state['trial_started_at'] = time();

	$configurationChanged = true;
	$active = $previousConfiguration;
	$active['ArticleTopics'] = array_replace($config, [
		'enabled' => true, 'mode' => 'hybrid', 'max_topics' => 1, 'protect_existing_labels' => true,
		'api_url' => $url, 'api_key' => $apiKey, 'model' => $options->model,
		'reasoning_effort' => 'none', 'response_format' => 'json_schema', 'think' => true,
		'taxonomy_json' => json_encode($topics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
	]);
	$configuration->extensions = $active;

	$tempProfile = $profilePath . '.trial.tmp';
	if (file_put_contents($tempProfile, $profile, LOCK_EX) !== strlen($profile) || !rename($tempProfile, $profilePath)) {
		throw new RuntimeException('Could not save the trial topic list.');
	}
	$changedFiles[] = $profilePath;
	ArticleTopicsStore::writeState($directory, $state);
	$changedFiles[] = $statePath;
	if (!$configuration->save()) { throw new RuntimeException('Could not save the trial configuration.'); }
	invalidateHttpCache();
	printf("New-arrivals trial started %s. Protected %d existing articles.\n", gmdate(DATE_ATOM, $state['trial_started_at']), count($state['processed_ids']));
} catch (Throwable $error) {
	foreach ($changedFiles as $path) {
		if ($previousFiles[$path] === null) {
			unlink($path);
		} else {
			file_put_contents($path, $previousFiles[$path], LOCK_EX);
		}
	}
	if ($configurationChanged) {
		$configuration->extensions = $previousConfiguration;
		$configuration->save();
	}
	fail($error->getMessage());
} finally {
	flock($lock, LOCK_UN);
	fclose($lock);
}
