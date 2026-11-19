<?php
declare(strict_types=1);
// Only the disposable development checkout is supported by this assertion suite.
$repo = dirname(__DIR__, 2);
$root = $repo . '/.upstream/FreshRSS';
require $root . '/cli/_cli.php';
$testUser = getenv('M2_TEST_USER') ?: 'm2test';
if (!preg_match('/^m2test[0-9]*$/', $testUser)) throw new RuntimeException('Only disposable m2test users are supported.');
cliInitUser($testUser);
require_once $root . '/extensions/xExtension-ArticleTopics/lib/ArticleTopicsStore.php';
require_once $root . '/extensions/xExtension-ArticleTopics/lib/ArticleTopicsWorker.php';
function expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
function mock(string $path, ?array $data = null): array {
    $ch=curl_init('http://127.0.0.1:8081/' . $path); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5]);
    if ($data !== null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode((object)$data)]);
    $result=curl_exec($ch); curl_close($ch);
    return json_decode((string)$result, true, 512, JSON_THROW_ON_ERROR);
}
final class FixtureDb extends FreshRSS_TagDAO {
    public function rows(string $sql, array $params=[]): array { $s=$this->pdo->prepare($sql); if (!$s || !$s->execute($params)) throw new RuntimeException('Fixture SQL failed: '.$sql); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public function begin(): void { $this->pdo->beginTransaction(); }
    public function undo(): void { $this->pdo->rollBack(); }
}
$db = new FixtureDb();
$dir = USERS_PATH . '/' . $testUser . '/extensions/ArticleTopics';
$config = ['enabled'=>true,'mode'=>'hybrid','api_url'=>'http://127.0.0.1:8081/v1','model'=>'mock-m2','response_format'=>'json_object','taxonomy_json'=>ArticleTopicsStore::defaultTaxonomyJson(),'max_attempts'=>5,'retry_base_seconds'=>60,'retention_enabled'=>true];
$user = FreshRSS_Context::userConf();
$enabled = $user->extensions_enabled; $enabled['ArticleTopics']=true; $user->extensions_enabled=$enabled;
$all = $user->extensions; $all['ArticleTopics']=$config; $user->extensions=$all; $user->save();
FreshRSS_Context::systemConf()->internal_host_allowlist=['127.0.0.1:8081']; FreshRSS_Context::systemConf()->save();
expect($db->rows('SELECT id FROM `_entry`') === [], 'fresh isolated fixture user has no articles');
ArticleTopicsStore::enableAtHighWater($dir);
$feed = FreshRSS_feed_Controller::addFeed('http://127.0.0.1:8081/feed.xml', 'General Tech Daily');
FreshRSS_feed_Controller::commitNewEntries();
$entries = $db->rows('SELECT id,title FROM `_entry`');
expect(count($entries) === 12, 'all twelve fixture articles imported');
function runWorker(array $config): void { global $testUser; expect((new ArticleTopicsWorker($testUser,$config))->run(50)===0, 'worker run completes'); }
function labelNames(FixtureDb $db, string $id): array { return array_column($db->rows('SELECT t.name FROM `_tag` t JOIN `_entrytag` a ON t.id=a.id_tag WHERE a.id_entry=? ORDER BY t.name',[$id]),'name'); }
mock('__test__/reset', []);
runWorker($config);
$fixtures=mock('__test__/fixtures');
$taxonomy=ArticleTopicsStore::validateTaxonomy($config['taxonomy_json']);
foreach ($fixtures as $fixture) {
    $match=array_values(array_filter($entries,static fn($e)=>$e['title']===$fixture['title']));
    $id=(string)$match[0]['id'];
    $expected=array_map(static fn($name)=>'Topic: '.$name,$fixture['topics']); sort($expected);
    // Negative-only fixtures specify forbidden topics rather than an exhaustive classification.
    if ($fixture['topics'] !== []) expect(labelNames($db,$id)===$expected, 'hybrid taxonomy: '.$fixture['title']);
    else expect(!in_array('Topic: Video Games',labelNames($db,$id),true) && !in_array('Topic: Technology',labelNames($db,$id),true), 'ambiguous fixture avoids unrelated topics: '.$fixture['title']);
    $keyword=(new ArticleTopicsClassifier(['mode'=>'keywords']))->classify(['title'=>$fixture['title'],'body'=>$fixture['content'],'tags'=>['General']],$taxonomy,microtime(true)+5);
    if ($fixture['topics'] !== []) { $names=$keyword['names']; sort($names); $want=$fixture['topics']; sort($want); expect($names===$want,'keyword taxonomy: '.$fixture['title']); }
}
$zelda=(string)array_values(array_filter($entries,static fn($e)=>$e['title']==='Nintendo announces new Zelda game'))[0]['id'];
$manual=$db->addTag(['name'=>'Manual']); expect(FreshRSS_Factory::createTagDao()->tagEntry((int)$manual,$zelda), 'manual label added using target database DAO');
$db->rows('UPDATE `_entry` SET is_read=1,is_favorite=1 WHERE id=?',[$zelda]);
$before=$db->rows('SELECT is_read,is_favorite FROM `_entry` WHERE id=?',[$zelda]);
$db->begin(); $db->rows('UPDATE `_entry` SET title=? WHERE id=?',['Financial markets respond to inflation figures',$zelda]); $db->undo();
runWorker($config);
expect(in_array('Topic: Video Games',labelNames($db,$zelda),true),'rolled-back update does not relabel');
$db->rows('UPDATE `_entry` SET title=?,content=? WHERE id=?',['Financial markets respond to inflation figures','Financial markets inflation',$zelda]);
runWorker($config);
expect(labelNames($db,$zelda)===['Manual','Topic: Finance'],'committed update replaces only owned labels');
expect($db->rows('SELECT is_read,is_favorite FROM `_entry` WHERE id=?',[$zelda])===$before,'read and starred states unchanged');
// LLM-only makes outage/retry/cache behavior observable without keyword short circuiting.
$llm=$config; $llm['mode']='llm'; mock('__test__/responses',['responses'=>[429,500]]);
runWorker($llm);
$state=ArticleTopicsStore::readState($dir);
expect(count($state['failures'])===2,'429 and 500 failures are durable while later entries continue');
foreach ($state['failures'] as &$failure) $failure['next_retry_at']=0; unset($failure); ArticleTopicsStore::writeState($dir,$state);
runWorker($llm); expect(ArticleTopicsStore::readState($dir)['failures']===[],'eligible retries succeed and clear failures');
$calls=mock('__test__/state')['calls']; runWorker($llm); expect(mock('__test__/state')['calls']===$calls,'unchanged articles require no extra LLM calls');
$state=ArticleTopicsStore::readState($dir); $state['pending'][]=(int)$zelda; $state['processed_ids']=array_values(array_diff($state['processed_ids'],[(int)$zelda])); ArticleTopicsStore::writeState($dir,$state);
runWorker($llm); expect(mock('__test__/state')['calls']===$calls,'cached identical inputs are reapplied without an LLM request');
$state=ArticleTopicsStore::readState($dir); $state['failures']['999999999999999']=['attempts'=>5,'next_retry_at'=>PHP_INT_MAX,'last_error'=>'deleted']; ArticleTopicsStore::writeState($dir,$state);
runWorker($llm); expect(!isset(ArticleTopicsStore::readState($dir)['failures']['999999999999999']),'deleted entries have capped failure records cleaned');
$db->rows('UPDATE `_entry` SET `date`=? WHERE id=?',[time()-200*86400,$zelda]);
runWorker($llm);
expect(labelNames($db,$zelda)===['Manual'],'retention removes old owned labels and keeps manual labels');
echo "Milestone 2 integration assertions completed.\n";
