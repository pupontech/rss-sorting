<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$pdo = FreshRSS_TagDAO::$testPdo;
$pdo->exec('CREATE TABLE IF NOT EXISTS `_at_owned` (id_entry INTEGER NOT NULL, id_tag INTEGER NOT NULL, PRIMARY KEY (id_entry, id_tag))');
$pdo->exec("INSERT OR IGNORE INTO `_entry`(id) VALUES(301),(302),(303)");
$pdo->exec("INSERT OR IGNORE INTO `_tag`(id,name) VALUES(3,'Topic: Old'),(4,'Manual')");
$fixtureNow = time();
$updateDate = $pdo->prepare('UPDATE `_entry` SET `date`=? WHERE id=?');
foreach ([301 => $fixtureNow - 2000, 302 => $fixtureNow - 2100, 303 => $fixtureNow] as $id => $date) { $updateDate->execute([$date, $id]); }
$pdo->exec("INSERT OR IGNORE INTO `_entrytag`(id_tag,id_entry) VALUES(3,301),(4,301),(3,302),(3,303)");
$pdo->exec("INSERT OR IGNORE INTO `_at_owned`(id_entry,id_tag) VALUES(301,3),(302,3),(303,3)");

$dao = new ArticleTopicsRetention();
$now = 2000;
assertSameValue(1136, ArticleTopicsRetention::cutoff(['retention_days' => 0.01], $now), 'explicit day cutoff uses Unix seconds');
assertSameValue(null, ArticleTopicsRetention::cutoff(['retention_enabled' => false, 'retention_days' => 1], $now), 'disabled retention has no cutoff');
assertSameValue(2000 - 90 * 86400, ArticleTopicsRetention::cutoff(['keep_period' => 'P90D'], $now), 'ISO retention period is supported');

$count = $dao->expireOwned(['retention_days' => '0.01'], microtime(true) + 5, 1);
assertSameValue(1, $count, 'expiration is bounded by batch size');
assertSameValue(1, (int)$pdo->query('SELECT COUNT(*) FROM `_entrytag` WHERE id_entry=301 AND id_tag=3')->fetchColumn(), 'unprocessed expired owned label remains for next batch');
$count += $dao->expireOwned(['retention_days' => '0.01'], microtime(true) + 5, 500);
assertSameValue(2, $count, 'both old owned labels expire');
assertSameValue(1, (int)$pdo->query('SELECT COUNT(*) FROM `_entrytag` WHERE id_entry=301 AND id_tag=4')->fetchColumn(), 'manual label on expired entry remains');
assertSameValue(1, (int)$pdo->query('SELECT COUNT(*) FROM `_entrytag` WHERE id_entry=303 AND id_tag=3')->fetchColumn(), 'newer owned label remains');
assertSameValue(1, (int)$pdo->query('SELECT COUNT(*) FROM `_at_owned` WHERE id_entry=303')->fetchColumn(), 'newer ownership remains');
