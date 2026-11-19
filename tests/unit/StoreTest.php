<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$valid = ArticleTopicsStore::validateTaxonomy('[{"name":" Games ","enabled":true,"description":" games "}]');
assertSameValue([['name' => 'Games', 'enabled' => true, 'description' => 'games', 'positive_keywords' => [], 'negative_keywords' => [], 'threshold' => 2, 'exclusions' => []]], $valid, 'taxonomy normalization');
foreach (['{', 'null', '[]', '[{"name":"Games","enabled":"yes","description":"x"}]', '[{"name":"Games","enabled":true,"description":"x"},{"name":" games ","enabled":false,"description":"y"}]'] as $invalidJson) {
	assertThrows(static fn() => ArticleTopicsStore::validateTaxonomy($invalidJson), Throwable::class, 'invalid taxonomy');
}
FreshRSS_Factory::$categoryDao = new TestCategoryDao([new TestCategory('topic: video games')]);
$error = assertThrows(static fn() => ArticleTopicsStore::assertNoCategoryCollisions('Topic: ', [['name' => 'Video Games', 'enabled' => true, 'description' => '']]), InvalidArgumentException::class, 'case-insensitive full-label collision');
if (!str_contains($error->getMessage(), 'Video Games')) throw new RuntimeException('collision error should identify the conflicting label');
FreshRSS_Factory::$categoryDao = new TestCategoryDao([new TestCategory('News')]);
ArticleTopicsStore::assertNoCategoryCollisions('Topic: ', [['name' => 'Video Games', 'enabled' => true, 'description' => '']]);

// Existing entries are excluded, but a late commit below the enable-time MAX
// must be discovered, and rescanning must not repeat a completed article.
FreshRSS_Factory::$entryDao->entries = ['200' => new FreshRSS_Entry('existing', 'body')];
$discoveryDir = USERS_PATH . '/discovery/extensions/ArticleTopics';
ArticleTopicsStore::enableAtHighWater($discoveryDir);
FreshRSS_Factory::$entryDao->entries['100'] = new FreshRSS_Entry('late commit', 'body');
ArticleTopicsStore::discoverCommitted($discoveryDir);
$discovery = ArticleTopicsStore::readState($discoveryDir);
assertSameValue([100], $discovery['pending'], 'late lower ID discovered without backfilling existing IDs');
$discovery['pending'] = [];
$discovery['processed_ids'][] = 100;
ArticleTopicsStore::writeState($discoveryDir, $discovery);
ArticleTopicsStore::discoverCommitted($discoveryDir);
assertSameValue([], ArticleTopicsStore::readState($discoveryDir)['pending'], 'discovery is idempotent');
