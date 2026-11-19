<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$root = $repo . '/.upstream/FreshRSS';
require $root . '/cli/_cli.php';
$userName = getenv('M2_TEST_USER') ?: '';
if (!preg_match('/^m2test[0-9]+$/', $userName)) throw new RuntimeException('Backfill tests require the disposable M2_TEST_USER.');
$userPath = USERS_PATH . '/' . $userName . '/extensions/ArticleTopics';
cliInitUser($userName);
$servicePath = $root . '/extensions/xExtension-ArticleTopics/lib/ArticleTopicsBackfill.php';
if (!is_file($servicePath)) throw new RuntimeException('Backfill implementation is missing: lib/ArticleTopicsBackfill.php');
require_once $root . '/extensions/xExtension-ArticleTopics/lib/ArticleTopicsStore.php';
require_once $root . '/extensions/xExtension-ArticleTopics/lib/ArticleTopicsWorker.php';
require_once $servicePath;

function backfillExpect(bool $ok, string $message): void {
	if (!$ok) throw new RuntimeException('FAIL: ' . $message);
	echo "PASS: {$message}\n";
}
function backfillMock(string $path, ?array $data = null): array {
	$ch = curl_init('http://127.0.0.1:8081/' . $path);
	curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
	if ($data !== null) curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode((object)$data, JSON_THROW_ON_ERROR)]);
	$raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
	if (!is_string($raw) || $status < 200 || $status >= 300) throw new RuntimeException("Mock request failed for {$path}: HTTP {$status} {$error}");
	return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
function backfillSnapshot(PDO $pdo): array {
	return $pdo->query('SELECT id,is_read,is_favorite FROM `_entry` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}
function backfillLabels(PDO $pdo): array {
	return $pdo->query('SELECT e.id,t.name FROM `_entry` e LEFT JOIN `_entrytag` et ON et.id_entry=e.id LEFT JOIN `_tag` t ON t.id=et.id_tag ORDER BY e.id,t.name')->fetchAll(PDO::FETCH_ASSOC);
}
final class BackfillFixtureDao extends FreshRSS_TagDAO {
	public function connection(): PDO { return $this->pdo; }
}

$config = FreshRSS_Context::userConf()->extensions['ArticleTopics'] ?? [];
$backfill = new ArticleTopicsBackfill($userName, $config);
$pdo = (new BackfillFixtureDao())->connection();
$entryState = backfillSnapshot($pdo);
$labelsBefore = backfillLabels($pdo);
$workerStateBefore = file_get_contents($userPath . '/state.json');
$callsBefore = (int)backfillMock('__test__/state')['calls'];

// Dry runs may persist job progress, but must not mutate article labels, read/star state,
// worker progress/failures/cache, or make provider requests.
$dry = $backfill->create(['scope'=>'all', 'dry_run'=>true]);
$dryResult = $backfill->run((string)$dry['id'], 2, 20);
backfillExpect((int)$dryResult['scanned'] === 2, 'dry-run scans only the requested page');
backfillExpect(backfillLabels($pdo) === $labelsBefore, 'dry-run leaves article labels unchanged');
backfillExpect(backfillSnapshot($pdo) === $entryState, 'dry-run leaves read and starred states unchanged');
backfillExpect(file_get_contents($userPath . '/state.json') === $workerStateBefore, 'dry-run leaves worker state, failures and cache unchanged');
$callsAfterDry = (int)backfillMock('__test__/state')['calls'];
backfillExpect($callsAfterDry - $callsBefore === (int)$dryResult['llm_calls'], 'dry-run LLM call counter matches provider requests');

// Resume the same bounded job in pages; cumulative counters and its keyset cursor survive calls.
$job = $backfill->create(['scope'=>'all', 'dry_run'=>false]);
$first = $backfill->run((string)$job['id'], 1, 20);
backfillExpect((int)$first['scanned'] === 1, 'first backfill page stops at its configured limit');
$firstCursor = (int)$first['cursor_id'];
$backfill = new ArticleTopicsBackfill($userName, $config); // simulate a fresh CLI process
$resumed = $backfill->run((string)$job['id'], 1, 20);
backfillExpect((int)$resumed['scanned'] === 2 && (int)$resumed['cursor_id'] > $firstCursor, 'backfill resumes after the saved keyset cursor');
$all = $resumed;
for ($i = 0; $i < 20 && ($all['status'] ?? '') !== 'complete'; $i++) {
	$all = $backfill->run((string)$job['id'], 2, 20);
}
backfillExpect(($all['status'] ?? '') === 'complete', 'backfill eventually completes after resumed pages');
backfillExpect((int)$all['scanned'] === count($entryState), 'backfill counters cover the captured article set exactly once');
backfillExpect((int)$all['high_water_id'] >= (int)$all['cursor_id'], 'job cursor remains bounded by its creation-time high-water mark');
backfillExpect(backfillSnapshot($pdo) === $entryState, 'real backfill leaves read and starred states unchanged');

// Verify unread and feed scopes. The previously read Zelda item is excluded.
$unread = $backfill->create(['scope'=>'unread']);
$unreadResult = $backfill->run((string)$unread['id'], 50, 20);
backfillExpect((int)$unreadResult['scanned'] === (int)$pdo->query('SELECT COUNT(*) FROM `_entry` WHERE is_read=0')->fetchColumn(), 'unread scope scans exactly the unread entries');
$zeldaId = (string)$pdo->query("SELECT id FROM `_entry` WHERE title='Nintendo announces new Zelda game'")->fetchColumn();
$readZelda = array_values(array_filter($entryState, static fn(array $row): bool => (string)$row['id'] === $zeldaId))[0] ?? null;
if ($readZelda !== null && (int)$readZelda['is_read'] === 1) {
	backfillExpect((int)$unreadResult['scanned'] < count($entryState), 'unread scope excludes the read article');
}
$feedJob = $backfill->create(['scope'=>'feed', 'feed_id'=>1]);
$feedResult = $backfill->run((string)$feedJob['id'], 50, 20);
backfillExpect((int)$feedResult['scanned'] === count($entryState), 'feed scope scans only its selected fixture feed');

// Review R1/R2 reproduction: failed-only scan cursor must not hide a retry
// below it, and one provider error must not prevent a later failed ID running.
$stateBeforeFailedScope = ArticleTopicsStore::readState($userPath);
$failedIds = $pdo->query('SELECT id FROM `_entry` WHERE `date` > '.(time()-86400*7).' ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$failedState = $stateBeforeFailedScope;
foreach ($failedIds as $failedId) $failedState['failures'][(string)$failedId]=['attempts'=>5,'next_retry_at'=>PHP_INT_MAX,'last_error'=>'fixture capped worker failure'];
ArticleTopicsStore::writeState($userPath,$failedState);
$failedConfig=$config; $failedConfig['mode']='llm';
$failedEngine=new ArticleTopicsBackfill($userName,$failedConfig);
$failedJob=$failedEngine->create(['scope'=>'failed']);
backfillMock('__test__/responses',['responses'=>[500]]);
$failedFirst=$failedEngine->run($failedJob['id'],1,20);
backfillExpect((int)$failedFirst['failed']===1 && (int)$failedFirst['llm_calls']===1,'failed-only provider error is durable and counted');
$failedSecond=$failedEngine->run($failedJob['id'],1,20);
backfillExpect((int)$failedSecond['scanned']===2 && $failedSecond['status']==='pending','failed-only scan reaches the next ID while earlier retry cools down');
$jobRecords=json_decode((string)file_get_contents($userPath.'/jobs.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($jobRecords[$failedJob['id']]['failures'] as &$failedRecord) $failedRecord['next_retry_at']=0; unset($failedRecord);
file_put_contents($userPath.'/jobs.json',json_encode($jobRecords,JSON_THROW_ON_ERROR));
$failedRecovered=(new ArticleTopicsBackfill($userName,$failedConfig))->run($failedJob['id'],1,20);
backfillExpect($failedRecovered['status']==='complete' && $failedRecovered['failures']===[], 'failed-only retry below the scan cursor is recovered after resume');
ArticleTopicsStore::writeState($userPath,$stateBeforeFailedScope);

// A second runner must not enter while this user's worker lock is held.
$lock = fopen($userPath . '/worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Could not acquire fixture worker lock.');
try {
	$lockedJob = $backfill->create(['scope'=>'all']);
	$lockedAfter = $backfill->run((string)$lockedJob['id'], 1, 20);
	backfillExpect((int)$lockedAfter['scanned'] === 0, 'backfill does not process entries while the per-user worker lock is held');
} finally {
	flock($lock, LOCK_UN);
	fclose($lock);
}

// Kill a real worker while it is blocked in the provider. The pending entry must
// remain durable, the OS must release the per-user lock, and the next worker must finish.
function runChild(string $script, bool $wait): array {
	$process = proc_open([PHP_BINARY, $script], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
	if (!is_resource($process)) throw new RuntimeException('Could not start fixture worker process.');
	fclose($pipes[0]);
	if (!$wait) return [$process, $pipes];
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	$exit = proc_close($process);
	return [$exit, (string)$out . (string)$err];
}
function runPhpCommand(array $arguments): array {
	$process = proc_open(array_merge([PHP_BINARY], $arguments), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
	if (!is_resource($process)) throw new RuntimeException('Could not start backfill CLI.');
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
	fclose($pipes[1]); fclose($pipes[2]);
	return [proc_close($process), (string)$out . (string)$err];
}
$killFixture = __DIR__ . '/worker_kill_fixture.php';
$callsBeforeKill = (int)backfillMock('__test__/state')['calls'];
backfillMock('__test__/delay', ['delay_ms'=>10000]);
[$child, $pipes] = runChild($killFixture, false);
$callsSeen = false;
for ($i=0; $i<80; $i++) {
	$stateResponse = backfillMock('__test__/state');
	if ((int)$stateResponse['calls'] > $callsBeforeKill) { $callsSeen = true; break; }
	if (!proc_get_status($child)['running']) break;
	usleep(100000);
}
$killedState = ArticleTopicsStore::readState($userPath);
$inFlightId = (string)($killedState['pending'][0] ?? '');
if (proc_get_status($child)['running']) { proc_terminate($child); }
fclose($pipes[1]); fclose($pipes[2]);
proc_close($child);
backfillMock('__test__/delay', ['delay_ms'=>0]);
backfillExpect($callsSeen, 'worker reached the delayed provider before forced interruption');
backfillExpect($inFlightId !== '', 'interrupted worker keeps its in-flight article pending');
[$recoveryExit, $recoveryOutput] = runChild($killFixture, true);
backfillExpect($recoveryExit === 0, 'worker starts again successfully after forced process termination');
$recoveredState = ArticleTopicsStore::readState($userPath);
backfillExpect($recoveredState['pending'] === [] && count($recoveredState['processed_ids']) === count($entryState), 'recovery processes the full batch after interruption');
backfillExpect(backfillSnapshot($pdo) === $entryState, 'interrupted and recovered worker leaves read and starred states unchanged');

$cli = $root . '/extensions/xExtension-ArticleTopics/cli/backfill.php';
[$cliExit, $cliOutput] = runPhpCommand([$cli, '--user=' . $userName, '--scope=all', '--dry-run', '--limit=1']);
if ($cliExit !== 0 || !preg_match('/\{.*\}\s*$/s', $cliOutput, $cliJson)) throw new RuntimeException('Backfill CLI failed to create/run a dry-run job: ' . trim($cliOutput));
$cliFirst = json_decode($cliJson[0], true, 512, JSON_THROW_ON_ERROR);
backfillExpect((int)$cliFirst['scanned'] === 1, 'CLI creates and processes a bounded dry-run job');
[$resumeExit, $resumeOutput] = runPhpCommand([$cli, '--user=' . $userName, '--job=' . $cliFirst['id'], '--limit=1']);
if ($resumeExit !== 0 || !preg_match('/\{.*\}\s*$/s', $resumeOutput, $resumeJson)) throw new RuntimeException('Backfill CLI could not resume its saved job: ' . trim($resumeOutput));
$cliResumed = json_decode($resumeJson[0], true, 512, JSON_THROW_ON_ERROR);
backfillExpect((int)$cliResumed['scanned'] === 2, 'CLI resumes an existing job by its durable job ID');

echo "Backfill SQLite integration assertions completed.\n";
