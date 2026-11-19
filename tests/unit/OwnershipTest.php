<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$worker = new ArticleTopicsWorker('unit', ['enabled' => true, 'api_url' => 'http://127.0.0.1:1', 'model' => 'mock']);
$apply = new ReflectionMethod(ArticleTopicsWorker::class, 'apply');
$apply->setAccessible(true);
$pdo = FreshRSS_TagDAO::$testPdo;
$pdo->exec("INSERT OR IGNORE INTO `_entry`(id) VALUES(101),(202)");
$pdo->exec("INSERT OR IGNORE INTO `_tag`(id,name) VALUES(2,'Topic: Games')");
$pdo->exec("INSERT OR IGNORE INTO `_entrytag`(id_tag,id_entry) VALUES(2,101)");

// A pre-existing article-label pair is manual, so reclassification must preserve it.
$apply->invoke($worker, 101, [], 'Topic: ');
assertSameValue(1, (int)$pdo->query("SELECT COUNT(*) FROM `_entrytag` WHERE id_tag=2 AND id_entry=101")->fetchColumn(), 'manual label association survives');
assertSameValue(0, (int)$pdo->query("SELECT COUNT(*) FROM `_at_owned` WHERE id_tag=2 AND id_entry=101")->fetchColumn(), 'manual association is not claimed as owned');

// The label association and ownership row share one database transaction.
$pdo->exec("CREATE TRIGGER fail_ownership BEFORE INSERT ON `_at_owned` BEGIN SELECT RAISE(ABORT, 'simulated interruption after pair insert'); END");
assertThrows(static fn() => $apply->invoke($worker, 202, ['Games'], 'Topic: '), RuntimeException::class, 'simulated interruption');
$pdo->exec('DROP TRIGGER fail_ownership');
assertSameValue(0, (int)$pdo->query("SELECT COUNT(*) FROM `_entrytag` WHERE id_entry=202")->fetchColumn(), 'interrupted transaction rolls association back');
assertSameValue(0, (int)$pdo->query("SELECT COUNT(*) FROM `_at_owned` WHERE id_entry=202")->fetchColumn(), 'interrupted transaction rolls ownership back');
$apply->invoke($worker, 202, ['Games'], 'Topic: ');
assertSameValue(1, (int)$pdo->query("SELECT COUNT(*) FROM `_entrytag` WHERE id_entry=202")->fetchColumn(), 'retry applies association');
assertSameValue(1, (int)$pdo->query("SELECT COUNT(*) FROM `_at_owned` WHERE id_entry=202")->fetchColumn(), 'retry records ownership');
$apply->invoke($worker, 202, [], 'Topic: ');
assertSameValue(0, (int)$pdo->query("SELECT COUNT(*) FROM `_entrytag` WHERE id_entry=202")->fetchColumn(), 'reclassification removes extension-owned association');
