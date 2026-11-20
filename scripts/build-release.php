<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const EXTENSION = ROOT . '/xExtension-ArticleTopics';

function fail(string $message): never {
	fwrite(STDERR, $message . "\n");
	exit(1);
}

$metadataPath = EXTENSION . '/metadata.json';
$metadata = json_decode((string)file_get_contents($metadataPath), true, 512, JSON_THROW_ON_ERROR);
$version = $metadata['version'] ?? null;
if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
	fail('metadata.json must contain a valid semantic version.');
}

$outputDir = ROOT . '/dist';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
	fail('Could not create dist directory.');
}
$output = $outputDir . '/xExtension-ArticleTopics-' . $version . '.zip';
$entries = [];
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator(EXTENSION, FilesystemIterator::SKIP_DOTS),
	RecursiveIteratorIterator::LEAVES_ONLY
);
$allowedFiles = [
	'configure.phtml' => true,
	'extension.php' => true,
	'metadata.json' => true,
	'README.md' => true,
	'static/admin.js' => true,
	'static/admin.css' => true,
	'profiles/one-topic.json' => true,
];
foreach ($iterator as $file) {
	if (!$file->isFile()) continue;
	$relative = substr($file->getPathname(), strlen(EXTENSION) + 1);
	$relative = str_replace('\\', '/', $relative);
	if (!isset($allowedFiles[$relative]) && !str_starts_with($relative, 'cli/') && !str_starts_with($relative, 'lib/')) continue;
	$entries['xExtension-ArticleTopics/' . $relative] = $file->getPathname();
}
foreach (['LICENSE' => ROOT . '/LICENSE', 'NOTICE.txt' => __DIR__ . '/NOTICE.txt'] as $file => $source) {
	$entries['xExtension-ArticleTopics/' . $file] = $source;
}
$entries['xExtension-ArticleTopics/README.md'] = EXTENSION . '/README.md';
ksort($entries, SORT_STRING);

if (in_array('--check', $argv, true)) {
	foreach ($entries as $archivePath => $sourcePath) {
		if (!is_file($sourcePath) || str_contains($archivePath, 'tests/') || str_contains($archivePath, '.git/')) {
			fail('Invalid release entry: ' . $archivePath);
		}
	}
	echo 'Release manifest OK: ' . count($entries) . " files for version {$version}.\n";
	exit(0);
}
if (!class_exists(ZipArchive::class)) fail('The PHP zip extension is required to build the release archive.');

$temporary = $output . '.tmp';
$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) fail('Could not create release archive.');
$fixedTime = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));
foreach ($entries as $archivePath => $sourcePath) {
	if (!$zip->addFile($sourcePath, $archivePath)) {
		$zip->close();
		@unlink($temporary);
		fail('Could not add release file: ' . $archivePath);
	}
	$zip->setMtimeName($archivePath, $fixedTime->getTimestamp());
}
if (!$zip->close()) fail('Could not finalize release archive.');
if (is_file($output) && !unlink($output)) fail('Could not replace previous release archive.');
if (!rename($temporary, $output)) fail('Could not publish release archive.');
echo $output . "\n";
