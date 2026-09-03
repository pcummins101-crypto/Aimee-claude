<?php
require_once __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/test-*.php') as $file) {
    echo 'Running ' . basename($file) . "\n";
    require $file;
}

$r = $GLOBALS['aimee_test_results'];
echo "\n{$r['pass']} passed, {$r['fail']} failed\n";
exit($r['fail'] ? 1 : 0);
