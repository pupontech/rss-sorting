<?php
declare(strict_types=1);

require_once __DIR__ . '/unit/bootstrap.php';
$root = dirname(__DIR__);
$extensionLib = $root . '/xExtension-ArticleTopics/lib';
foreach (glob($extensionLib . '/*.php') ?: [] as $sourceFile) require_once $sourceFile;
$tests = glob(__DIR__ . '/unit/*Test.php') ?: [];
if ($tests === []) { fwrite(STDERR, "No unit tests found.\n"); exit(1); }
$failures = [];
foreach ($tests as $testFile) {
	try { require $testFile; printf("PASS %s\n", basename($testFile)); }
	catch (Throwable $error) { $failures[] = [$testFile, $error]; printf("FAIL %s: %s\n", basename($testFile), $error->getMessage()); }
}
printf("%d test file(s), %d failure(s).\n", count($tests), count($failures));
exit($failures === [] ? 0 : 1);