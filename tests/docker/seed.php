<?php
declare(strict_types=1);

require '/var/www/FreshRSS/cli/_cli.php';
cliInitUser('demo');

// Permit only the isolated fixture host; FreshRSS blocks private feeds by default.
FreshRSS_Context::systemConf()->internal_host_allowlist = ['mock:8081'];
FreshRSS_Context::systemConf()->save();

$config = FreshRSS_Context::userConf();
$enabled = $config->extensions_enabled;
$enabled['ArticleTopics'] = true;
$config->extensions_enabled = $enabled;
$extensions = $config->extensions;
$extensions['ArticleTopics'] = [
	'enabled' => true,
	'api_url' => 'http://mock:8081/v1',
	'api_key' => 'test-key',
	'model' => 'deterministic-mock',
	'label_prefix' => 'Topic: ',
	'content_limit' => 4000,
	'taxonomy_json' => json_encode([
		['name' => 'Video Games', 'enabled' => true, 'description' => 'Video games and gaming.'],
		['name' => 'Finance', 'enabled' => true, 'description' => 'Financial markets and economics.'],
	], JSON_THROW_ON_ERROR),
];
$config->extensions = $extensions;
if (!$config->save()) {
	fwrite(STDERR, "Could not save test user configuration.\n");
	exit(1);
}

require_once '/var/www/FreshRSS/extensions/xExtension-ArticleTopics/lib/ArticleTopicsStore.php';
ArticleTopicsStore::enableAtHighWater(USERS_PATH . '/demo/extensions/ArticleTopics');

$feed = 'http://mock:8081/feed.xml';
$dao = FreshRSS_Factory::createFeedDao();
if ($dao->searchByUrl($feed) === null) {
	FreshRSS_feed_Controller::addFeed($feed, 'General Tech Daily');
}
$feedRecord = $dao->searchByUrl($feed);
echo json_encode(['feed_id' => $feedRecord?->id()], JSON_THROW_ON_ERROR), "\n";
