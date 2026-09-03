<?php
/** Parse every shipped production PHP file without executing WordPress code. */

$plugin_root = is_dir('/aimee/includes') ? '/aimee' : dirname(__DIR__);
$paths = [$plugin_root . '/aimee-global.php'];
foreach ([$plugin_root . '/includes', $plugin_root . '/templates'] as $root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $paths[] = $file->getPathname();
        }
    }
}

sort($paths);
$parsed = 0;
$failures = [];
foreach ($paths as $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        $failures[] = $path . ': unreadable';
        continue;
    }

    try {
        token_get_all($source, TOKEN_PARSE);
        $parsed++;
    } catch (ParseError $error) {
        $failures[] = $path . ': ' . $error->getMessage();
    }
}

if ($failures) {
    foreach ($failures as $failure) echo "FAIL {$failure}\n";
    echo "Production PHP syntax: {$parsed}/" . count($paths) . " parsed.\n";
    exit(1);
}

echo 'PASS production PHP syntax parsed ' . $parsed . '/' . count($paths) . " files.\n";
exit(0);
