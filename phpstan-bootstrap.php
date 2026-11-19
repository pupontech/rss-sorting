<?php
declare(strict_types=1);

// Load FreshRSS's real 1.30.4 class autoloader so PHPStan checks calls against
// the same APIs used at runtime instead of guessed local framework stubs.
$freshRssRoot = __DIR__ . '/.upstream/FreshRSS';
if (!is_file($freshRssRoot . '/constants.php')) {
	fwrite(STDERR, "FreshRSS 1.30.4 source is required at .upstream/FreshRSS for PHPStan.\n");
	exit(1);
}
require_once $freshRssRoot . '/constants.php';
if (FRESHRSS_VERSION !== '1.30.4') {
	fwrite(STDERR, 'PHPStan expected FreshRSS 1.30.4, got ' . FRESHRSS_VERSION . ".\n");
	exit(1);
}
define('WITH_COMPOSER', true);
require_once LIB_PATH . '/lib_rss.php';
// Load CLI option types without running FreshRSS's CLI initializer (which
// starts sessions and initializes the application as a command-line process).
require_once $freshRssRoot . '/cli/CliOption.php';
require_once $freshRssRoot . '/cli/CliOptionsParser.php';
