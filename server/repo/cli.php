<?php
// Build from the command line (SSH, cron or a local PHP): php repo/cli.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('CYREPO', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/site.php';
try {
    $result = cy_build(dirname(__DIR__));
} catch (CyError $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($result['warnings'] as $warning) {
    fwrite(STDERR, "  ! $warning\n");
}
printf("Done: %d package(s), %d .deb file(s) in %.2fs\n", $result['packages'], $result['debs'], $result['seconds']);
