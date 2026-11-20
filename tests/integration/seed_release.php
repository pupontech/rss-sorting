<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
$feed = $argv[2] ?? '';
if (!preg_match('#^http://127\.0\.0\.1:[0-9]+/feed\.xml$#D', $feed)) {
	throw new InvalidArgumentException('The release test requires a loopback fixture feed.');
}

require $root . '/cli/_cli.php';
cliInitUser('stagingowner');
$system = FreshRSS_Context::systemConf();
if (!str_starts_with($system->base_url, 'http://127.0.0.1:')) {
	throw new RuntimeException('The release test requires a loopback FreshRSS install.');
}

$system->extensions = ['ArticleTopics' => ['allowed_users' => ['stagingowner']]];
$system->internal_host_allowlist = [(string)parse_url($feed, PHP_URL_HOST) . ':' . parse_url($feed, PHP_URL_PORT)];
$system->save();

FreshRSS_feed_Controller::addFeed($feed, 'Article Topics test feed');
FreshRSS_feed_Controller::commitNewEntries();

$dao = new class extends FreshRSS_TagDAO {
	public function connection(): PDO {
		return $this->pdo;
	}
};
$pdo = $dao->connection();
$zelda = $pdo->query("SELECT id FROM `_entry` WHERE title='Nintendo announces new Zelda game'")->fetchColumn();
if ($zelda === false) {
	throw new RuntimeException('The Zelda test article is missing.');
}
$pdo->prepare('UPDATE `_entry` SET is_read=1, is_favorite=1 WHERE id=?')->execute([$zelda]);
$tags = FreshRSS_Factory::createTagDao();
$manualLabel = $tags->addTag(['name' => 'Manual test label']);
$manualTopic = $tags->addTag(['name' => 'Topic: Video Games']);
if ($manualLabel === false || $manualTopic === false
	|| !$tags->tagEntry((int)$manualLabel, (string)$zelda)
	|| !$tags->tagEntry((int)$manualTopic, (string)$zelda)) {
	throw new RuntimeException('Could not apply manual fixture labels.');
}
