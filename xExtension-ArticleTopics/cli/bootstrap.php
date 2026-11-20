<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit;
}

$freshRssRoot = getenv('ARTICLETOPICS_FRESHRSS_ROOT');
if ($freshRssRoot === false || $freshRssRoot === '') {
	// A symlinked extensions directory may resolve outside the application.
	// The invocation path can still point through the FreshRSS installation.
	$freshRssRoot = dirname(__DIR__, 3);
	if (!is_file($freshRssRoot . '/cli/_cli.php')) {
		$freshRssRoot = dirname($_SERVER['SCRIPT_FILENAME'] ?? '', 4);
	}
}
if (!is_file($freshRssRoot . '/cli/_cli.php')) {
	fwrite(STDERR, "FreshRSS root not found. Set ARTICLETOPICS_FRESHRSS_ROOT to the directory containing cli/_cli.php.\n");
	exit(1);
}

require $freshRssRoot . '/cli/_cli.php';
require_once dirname(__DIR__) . '/lib/ArticleTopicsAccess.php';
