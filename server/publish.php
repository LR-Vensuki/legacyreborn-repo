<?php
// Publishing endpoint for tools/repo.py. Requests are signed with the key in
// repo/secret.php; anything unsigned is rejected. See repo/lib/api.php.
define('CYREPO', 1);
require __DIR__ . '/repo/lib/core.php';
require __DIR__ . '/repo/lib/site.php';
require __DIR__ . '/repo/lib/api.php';
cy_api(__DIR__);
