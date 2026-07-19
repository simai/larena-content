<?php

declare(strict_types=1);

$files = [];
foreach (['src', 'tests', 'scripts', 'tools'] as $path) {
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
foreach ($files as $file) {
    passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file), $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
echo 'Linted ' . count($files) . " PHP file(s).\n";
