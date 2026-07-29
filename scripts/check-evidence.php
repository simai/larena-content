<?php

declare(strict_types=1);

const EVIDENCE_PATH = 'docs/project-management/evidence/cms-operator-content-admin/content/';

$errors = [];
$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($context) || ($context['evidence_path'] ?? null) !== EVIDENCE_PATH) {
    $errors[] = 'Launch context does not point to the CMS operator Content evidence bundle.';
}

foreach (['implementation-summary.md', 'tests.md', 'browser-acceptance.md', 'simplicity-verdict.json', 'graph-sync-proposal.json'] as $file) {
    $path = EVIDENCE_PATH.$file;
    if (!is_file($path) || trim((string) file_get_contents($path)) === '') {
        $errors[] = "Missing or empty evidence file: {$path}";
        continue;
    }
    $contents = (string) file_get_contents($path);
    foreach (['-----BEGIN PRIVATE KEY-----', 'github_pat_', 'Authorization: Bearer ', 'DB_PASSWORD=', 'MYSQL_PWD='] as $marker) {
        if (str_contains($contents, $marker)) {
            $errors[] = "Evidence contains a forbidden secret marker: {$path}";
        }
    }
    if (str_ends_with($file, '.json')) {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                $errors[] = "Evidence JSON must contain an object: {$path}";
            }
        } catch (Throwable $exception) {
            $errors[] = "Invalid evidence JSON {$path}: {$exception->getMessage()}";
        }
    }
}

$tests = (string) @file_get_contents(EVIDENCE_PATH.'tests.md');
foreach (['SQLite', 'MySQL', 'restart', 'rollback', 'production_ready=false', 'frontend_complete=false', 'all_42_packages_ready=false'] as $receipt) {
    if (!str_contains($tests, $receipt)) {
        $errors[] = "tests.md does not yet contain required receipt: {$receipt}";
    }
}

if (($context['review_completed'] ?? null) !== true || ($context['independent_review_verdict'] ?? null) !== 'accepted_with_nonclaims') {
    $errors[] = 'Evidence must retain the accepted tester verdict and explicit nonclaims.';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_values(array_unique($errors))).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'CMS operator Content evidence bundle is structurally complete.'.PHP_EOL);
