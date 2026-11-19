<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$directories = [$root . '/xExtension-ArticleTopics', $root . '/tests', $root . '/scripts'];
$files = [];
foreach ($directories as $directory) {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'phtml'], true)) $files[] = $file->getPathname();
	}
}
sort($files, SORT_STRING);
$failed = false;
foreach ($files as $file) {
	$command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
	exec($command, $output, $status);
	if ($status !== 0) {
		fwrite(STDERR, implode("\n", $output) . "\n");
		$failed = true;
	} else {
		echo 'PASS lint ' . str_replace($root . DIRECTORY_SEPARATOR, '', $file) . "\n";
	}
	$output = [];
}
echo count($files) . ' PHP file(s) linted' . ($failed ? ' with failures.' : ' successfully.') . "\n";
exit($failed ? 1 : 0);
