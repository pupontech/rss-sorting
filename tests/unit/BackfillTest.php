<?php
declare(strict_types=1);

$dao = FreshRSS_Factory::createEntryDao();
$priorEntries = $dao->entries;
$dao->entries = [
	'101' => new FreshRSS_Entry('Backfill Nintendo Zelda game', 'A new game announced.'),
	'102' => new FreshRSS_Entry('Backfill market report', 'Financial markets and inflation.'),
];
$config = [
	'enabled' => true,
	'mode' => 'keywords',
	'label_prefix' => 'Topic: ',
	'taxonomy_json' => json_encode([
		['name'=>'Video Games','enabled'=>true,'description'=>'','positive_keywords'=>['Nintendo'=>3],'negative_keywords'=>[],'threshold'=>2,'exclusions'=>[]],
	], JSON_THROW_ON_ERROR),
];
$engine = new ArticleTopicsBackfill('backfill-unit', $config);
$dry = $engine->create(['scope'=>'all','dry_run'=>true]);
$dryResult = $engine->run($dry['id'], 1, 5);
assertSameValue(1, $dryResult['scanned'], 'dry-run advances one keyset item');
assertSameValue('pending', $dryResult['status'], 'limited dry-run remains resumable');
assertSameValue(['Video Games'], $dryResult['last_result']['labels'], 'dry-run previews classification');
$tagDao = new FreshRSS_TagDAO();
assertSameValue(null, $tagDao->searchByName('Topic: Video Games'), 'dry-run creates no labels');
$resumed = $engine->run($dry['id'], 5, 5);
assertSameValue('complete', $resumed['status'], 'dry-run resumes to completion');
assertSameValue(2, $resumed['scanned'], 'resumed job keeps cumulative counters');

$live = $engine->create(['scope'=>'all','dry_run'=>false]);
$liveResult = $engine->run($live['id'], 5, 5);
assertSameValue(1, $liveResult['labelled'], 'live backfill applies classified label');
assertSameValue(1, $liveResult['skipped'], 'unclassified article is counted as skipped');
assertSameValue(2, count($engine->jobs()), 'jobs lists per-user durable jobs');

assertThrows(static fn() => $engine->create(['scope'=>'feed']), InvalidArgumentException::class, 'feed scope requires feed_id');
$dao->entries = $priorEntries;
