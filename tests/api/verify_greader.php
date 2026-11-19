<?php
declare(strict_types=1);

/**
 * End-to-end Google Reader API verification for FreshRSS 1.30.4.
 * Run: php tests/api/verify_greader.php
 */

const DEFAULT_BASE = 'http://localhost:8088/api/greader.php';

function envValue(string $name, string $default): string {
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
}

function fail(string $message): never {
	throw new RuntimeException('FAIL: ' . $message);
}

function check(bool $condition, string $message): void {
	if (!$condition) fail($message);
	fwrite(STDOUT, "PASS: {$message}\n");
}

function request(string $method, string $path, array $params = [], ?string $auth = null): array {
	$url = rtrim(envValue('FRESHRSS_API_BASE', DEFAULT_BASE), '/') . '/' . ltrim($path, '/');
	if ($method === 'GET' && $params !== []) $url .= '?' . http_build_query($params);
	$headers = ['Accept: application/json, text/plain;q=0.9'];
	if ($auth !== null) $headers[] = 'Authorization: GoogleLogin auth=' . $auth;
	$options = ['http' => [
		'method' => $method,
		'header' => implode("\r\n", $headers),
		'ignore_errors' => true,
		'timeout' => (float)envValue('FRESHRSS_HTTP_TIMEOUT', '20'),
	]];
	if ($method === 'POST') {
		$options['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
		$options['http']['content'] = http_build_query($params);
	}
	$context = stream_context_create($options);
	$body = @file_get_contents($url, false, $context);
	if ($body === false) fail("HTTP request failed: {$method} {$url}");
	$status = 0;
	foreach ($http_response_header ?? [] as $header) {
		if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) $status = (int)$m[1];
	}
	if ($status < 200 || $status >= 300) fail("HTTP {$status} from {$path}: " . trim($body));
	return [$body, $status];
}

function jsonRequest(string $method, string $path, array $params, string $auth): array {
	[$body] = request($method, $path, $params, $auth);
	try { $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
	catch (JsonException $e) { fail("Invalid JSON from {$path}: " . $e->getMessage()); }
	if (!is_array($data)) fail("Unexpected JSON shape from {$path}");
	return $data;
}

function articleIds(array $stream): array {
	$ids = [];
	foreach (($stream['items'] ?? []) as $item) {
		if (isset($item['id'])) $ids[] = (string)$item['id'];
	}
	return $ids;
}

function getStream(string $stream, string $auth): array {
	return jsonRequest('GET', 'reader/api/0/stream/contents/' . $stream, ['output' => 'json', 'n' => 1000], $auth);
}

function getArticle(string $id, string $auth): array {
	$data = jsonRequest('POST', 'reader/api/0/stream/items/contents', ['output' => 'json', 'i' => $id], $auth);
	if (!isset($data['items'][0]) || !is_array($data['items'][0])) fail("Article {$id} not found via stream/items/contents");
	return $data['items'][0];
}

function setState(string $id, string $state, bool $add, string $token, string $auth): void {
	request('POST', 'reader/api/0/edit-tag', [
		'T' => $token,
		'i' => $id,
		($add ? 'a' : 'r') => 'user/-/state/com.google/' . $state,
	], $auth);
}

$user = envValue('FRESHRSS_USER', 'admin');
$password = envValue('FRESHRSS_PASSWORD', 'test-api-password');
$titleExpected = envValue('FRESHRSS_EXPECTED_TITLE', 'Nintendo announces new Zelda game');
$labelName = envValue('FRESHRSS_TOPIC_LABEL', 'Topic: Video Games');
$labelStream = envValue('FRESHRSS_TOPIC_STREAM', 'user/-/label/' . rawurlencode($labelName));
$feedStream = getenv('FRESHRSS_ORIGINAL_FEED_STREAM') ?: '';
$articleId = getenv('FRESHRSS_ARTICLE_ID') ?: '';
$feedId = getenv('FRESHRSS_ORIGINAL_FEED_ID') ?: '';
$feedTitle = envValue('FRESHRSS_ORIGINAL_FEED_TITLE', 'General Tech Seed Feed');

// Use the opaque Auth value issued by FreshRSS; never derive it locally.
[$loginBody] = request('POST', 'accounts/ClientLogin', ['Email' => $user, 'Passwd' => $password]);
if (!preg_match('/(?:^|\n)Auth=([^\r\n]+)/', $loginBody, $loginMatch)) fail('ClientLogin response did not contain Auth');
$auth = trim($loginMatch[1]);
$tokenBody = request('GET', 'reader/api/0/token', [], $auth)[0];
$token = trim($tokenBody);
if ($token === '') fail('Reader API token was empty');
fwrite(STDOUT, "Authenticated as {$user}.\n");

$tags = jsonRequest('GET', 'reader/api/0/tag/list', ['output' => 'json'], $auth);
$tagFound = false;
foreach (($tags['tags'] ?? []) as $tag) {
	if (($tag['id'] ?? null) === 'user/-/label/' . $labelName) {
		$tagFound = true;
		check(($tag['type'] ?? null) === 'tag', "{$labelName} is exposed as a user label (type=tag)");
		break;
	}
}
check($tagFound, "{$labelName} is present in tag/list");

$topic = getStream($labelStream, $auth);
$topicIds = articleIds($topic);
if ($articleId === '') {
	foreach (($topic['items'] ?? []) as $item) {
		if (isset($item['title']) && strcasecmp(trim((string)$item['title']), trim($titleExpected)) === 0) {
			$articleId = (string)$item['id'];
			break;
		}
	}
}
if ($articleId === '') fail("Expected article not found in topic stream; set FRESHRSS_ARTICLE_ID. Expected title: {$titleExpected}");
check(in_array($articleId, $topicIds, true), "expected article is present in {$labelName}");
foreach (($topic['items'] ?? []) as $item) {
	check(($item['title'] ?? '') !== 'Financial markets respond to inflation figures', 'finance fixture is absent from the Video Games stream');
}
$article = getArticle($articleId, $auth);
$actualTitle = trim((string)($article['title'] ?? ''));
check(strcasecmp($actualTitle, trim($titleExpected)) === 0, "article title matches configured fixture ({$actualTitle})");

if ($feedStream === '' && $feedId !== '') $feedStream = 'feed/' . rawurlencode($feedId);
if ($feedStream === '') fail('Set FRESHRSS_ORIGINAL_FEED_STREAM or FRESHRSS_ORIGINAL_FEED_ID');
$original = getStream($feedStream, $auth);
check(in_array($articleId, articleIds($original), true), "article remains in its original feed stream ({$feedTitle})");

$counts = jsonRequest('GET', 'reader/api/0/unread-count', ['output' => 'json'], $auth);
$beforeCount = null;
foreach (($counts['unreadcounts'] ?? []) as $count) {
	if (($count['id'] ?? null) === rawurldecode($labelStream)) $beforeCount = (int)($count['count'] ?? 0);
}
check($beforeCount !== null, "unread-count includes the topic label stream (count=" . ($beforeCount ?? 'missing') . ')');

$initialRead = in_array('user/-/state/com.google/read', $article['categories'] ?? [], true);
$initialStarred = in_array('user/-/state/com.google/starred', $article['categories'] ?? [], true);
$changed = [];
try {
	setState($articleId, 'read', true, $token, $auth);
	$changed[] = 'read';
	$readArticle = getArticle($articleId, $auth);
	check(in_array('user/-/state/com.google/read', $readArticle['categories'] ?? [], true), 'read toggle persists through the API');
	setState($articleId, 'starred', true, $token, $auth);
	$changed[] = 'starred';
	$starArticle = getArticle($articleId, $auth);
	check(in_array('user/-/state/com.google/starred', $starArticle['categories'] ?? [], true), 'star toggle persists through the API');
} finally {
	if (in_array('starred', $changed, true) && !$initialStarred) setState($articleId, 'starred', false, $token, $auth);
	if (in_array('read', $changed, true) && !$initialRead) setState($articleId, 'read', false, $token, $auth);
}
$restored = getArticle($articleId, $auth);
check(in_array('user/-/state/com.google/read', $restored['categories'] ?? [], true) === $initialRead, 'read state restored to its initial value');
check(in_array('user/-/state/com.google/starred', $restored['categories'] ?? [], true) === $initialStarred, 'star state restored to its initial value');
$afterCounts = jsonRequest('GET', 'reader/api/0/unread-count', ['output' => 'json'], $auth);
$afterCount = null;
foreach (($afterCounts['unreadcounts'] ?? []) as $count) if (($count['id'] ?? null) === rawurldecode($labelStream)) $afterCount = (int)($count['count'] ?? 0);
check($afterCount === $beforeCount, 'topic unread count is unchanged after restoring article state');

fwrite(STDOUT, "API verification completed successfully.\n");
