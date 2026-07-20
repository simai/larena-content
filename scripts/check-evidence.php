<?php

declare(strict_types=1);

const CONTENT_EVIDENCE_SPECS_COMMIT = 'b5ea1bc2386544d4a6f4e4af4ce172a28988f0be';
const CONTENT_EVIDENCE_BASE_COMMIT = '4f19197636b3878ac0732f0229cd898291bdd3cc';
const CONTENT_EVIDENCE_PATH = 'docs/project-management/evidence/data-content/content-model-administration-api-v1/';

/**
 * @return array<string, mixed>
 */
function evidence_json(string $path): array
{
    $decoded = json_decode(
        (string) file_get_contents($path),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException("Evidence JSON must contain an object: {$path}");
    }

    return $decoded;
}

function evidence_text_contains_secret(string $contents): bool
{
    foreach (
        [
            '-----BEGIN PRIVATE KEY-----',
            'ghp_',
            'github_pat_',
            'Authorization: Bearer ',
            'BITRIX_WEBHOOK',
            'DB_PASSWORD=',
            'MYSQL_PWD=',
        ] as $secretMarker
    ) {
        if (str_contains($contents, $secretMarker)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, string> $expected
 * @param array<string, mixed> $actual
 * @param list<string> $errors
 */
function evidence_compare_revision_map(
    array $expected,
    array $actual,
    array &$errors,
): void {
    ksort($expected, SORT_STRING);
    ksort($actual, SORT_STRING);

    if ($actual !== $expected) {
        $errors[] = 'dependency-revisions.json must contain the exact accepted Larena closure.';
    }
}

$errors = [];
$requiredFiles = [
    'README.md',
    'implementation-summary.md',
    'tests.md',
    'dependency-revisions.json',
    'verification-status.json',
    'graph-sync-proposal.json',
];

try {
    $context = evidence_json('.larena/launch-context.json');
} catch (Throwable $exception) {
    fwrite(STDERR, "Invalid launch context: {$exception->getMessage()}" . PHP_EOL);
    exit(1);
}

$evidencePath = rtrim((string) ($context['evidence_path'] ?? ''), '/') . '/';
if (($context['specs_commit'] ?? null) !== CONTENT_EVIDENCE_SPECS_COMMIT) {
    $errors[] = 'launch-context specs_commit is not the exact API v1 Specs commit.';
}
if (($context['base_commit'] ?? null) !== CONTENT_EVIDENCE_BASE_COMMIT) {
    $errors[] = 'launch-context base_commit is not the accepted Content starting revision.';
}
if ($evidencePath !== CONTENT_EVIDENCE_PATH) {
    $errors[] = 'launch-context evidence_path is not the exact API v1 evidence path.';
}
if (
    ($context['graph_sync_proposal_path'] ?? null)
    !== CONTENT_EVIDENCE_PATH . 'graph-sync-proposal.json'
) {
    $errors[] = 'graph_sync_proposal_path must resolve inside the API v1 evidence bundle.';
}
if (($context['status'] ?? null) !== 'implementation_verification_ready') {
    $errors[] = 'launch-context status must remain implementation_verification_ready.';
}
if (($context['review_completed'] ?? null) !== false) {
    $errors[] = 'implementation evidence must not pre-author an independent review.';
}
if (($context['independent_review_verdict'] ?? null) !== 'pending') {
    $errors[] = 'independent review must remain explicitly pending.';
}

foreach ($requiredFiles as $required) {
    $path = CONTENT_EVIDENCE_PATH . $required;
    if (!is_file($path)) {
        $errors[] = "Missing evidence file: {$path}";
        continue;
    }

    $contents = (string) file_get_contents($path);
    if (evidence_text_contains_secret($contents)) {
        $errors[] = "{$path} contains a forbidden secret marker.";
    }
}

$expectedRevisions = [
    'larena/access' => '8c0e75897fe422a8f4d97fc012f1d095ffdba3b2',
    'larena/admin' => '540e171625cd6a58e8ced00a085abfb45d9ad781',
    'larena/audit' => 'ab2546b1a0fdd577faba895755a3d6c44f0f9da8',
    'larena/auth' => '63bac556b36a25fe16885601aefe174d5d712c3a',
    'larena/cockpit' => 'd8074d30727d5c124928b8e47466f063eb746dbf',
    'larena/core' => '46f3bbc8baba0262117bc9b9519713ee21b1d981',
    'larena/dataview' => 'b84e964b4ed78e1ca08a46c88e7651b02744ee47',
    'larena/filesystem' => '6c784d0ad84e5fcc72b515c8b5b27bafac9ee31f',
    'larena/layout' => 'cb5bdadf588cb8480972279bea3888500dbf9d6e',
    'larena/licensing' => '52d1215a25369cca17d5170bbfcae82d1f6c86d2',
    'larena/link' => 'affc02abad5f3be568ae02c3678abe51d14575a9',
    'larena/property' => '92b6e915fc4c85239171dbbff6c3cb15d046cc99',
    'larena/rest' => '174dc005002a5ba0e77f906d3e9143ce89a5fd2b',
    'larena/search' => '9f5c1cf5d2b112751328520eee34826c19dd2535',
    'larena/storage' => '7645c0124999eeab6150edc0b0b949adc17be310',
    'larena/ui' => '07fff2579344d7c77a28716a74071fb53f0bbfc9',
    'larena/update' => '4c56bb8d26b6259ae71e58512ccadc2529accfec',
];

try {
    $revisionEvidence = evidence_json(
        CONTENT_EVIDENCE_PATH . 'dependency-revisions.json',
    );
    if (
        ($revisionEvidence['specs_commit'] ?? null) !== CONTENT_EVIDENCE_SPECS_COMMIT
        || ($revisionEvidence['exact_dependency_count'] ?? null) !== count($expectedRevisions)
        || ($revisionEvidence['clean_install'] ?? null) !== 'pass'
    ) {
        $errors[] = 'dependency revision evidence metadata is incomplete.';
    }
    evidence_compare_revision_map(
        $expectedRevisions,
        is_array($revisionEvidence['revisions'] ?? null)
            ? $revisionEvidence['revisions']
            : [],
        $errors,
    );
} catch (Throwable $exception) {
    $errors[] = 'Invalid dependency revision evidence: ' . $exception->getMessage();
}

try {
    $verification = evidence_json(
        CONTENT_EVIDENCE_PATH . 'verification-status.json',
    );
    foreach (
        [
            'specs_commit' => CONTENT_EVIDENCE_SPECS_COMMIT,
            'base_commit' => CONTENT_EVIDENCE_BASE_COMMIT,
            'transport_operations' => 20,
            'content_access_operations' => 18,
            'content_audit_events' => 12,
            'content_owned_tables' => 6,
            'fresh_clean_install' => 'pass',
            'lint' => 'pass',
            'phpstan' => 'pass',
            'independent_review' => 'pending',
            'root_acceptance' => 'pending',
            'active_runtime_changed' => false,
            'production_ready' => false,
            'frontend_ready' => false,
            'release_ready' => false,
            'all_packages_ready' => false,
        ] as $field => $expected
    ) {
        if (($verification[$field] ?? null) !== $expected) {
            $errors[] = "verification-status {$field} is not the bounded expected value.";
        }
    }
    if (
        !in_array(
            $verification['package_quality_gate'] ?? null,
            ['pending_governance_self_check', 'pass'],
            true,
        )
    ) {
        $errors[] = 'verification-status package_quality_gate has an invalid lifecycle value.';
    }
    if (
        ($verification['non_mysql_suite']['result'] ?? null) !== 'pass'
        || ($verification['non_mysql_suite']['tests'] ?? null) !== 188
        || ($verification['non_mysql_suite']['assertions'] ?? null) !== 1321
    ) {
        $errors[] = 'verification-status must retain the exact current non-MySQL receipt.';
    }
} catch (Throwable $exception) {
    $errors[] = 'Invalid verification status evidence: ' . $exception->getMessage();
}

try {
    $proposal = evidence_json(CONTENT_EVIDENCE_PATH . 'graph-sync-proposal.json');
    if (
        ($proposal['source_specs_commit'] ?? null) !== CONTENT_EVIDENCE_SPECS_COMMIT
        || ($proposal['source_package_base_commit'] ?? null) !== CONTENT_EVIDENCE_BASE_COMMIT
        || ($proposal['canonical_update_allowed'] ?? null) !== false
        || ($proposal['apply_requested'] ?? null) !== false
    ) {
        $errors[] = 'graph sync proposal must remain exact, proposal-only and unapplied.';
    }
    foreach (
        [
            'production_ready',
            'frontend_ready',
            'release_or_update_server_ready',
            'all_packages_ready',
        ] as $nonclaim
    ) {
        if (($proposal['nonclaims'][$nonclaim] ?? null) !== false) {
            $errors[] = "graph sync proposal must keep {$nonclaim}=false.";
        }
    }
} catch (Throwable $exception) {
    $errors[] = 'Invalid graph sync proposal: ' . $exception->getMessage();
}

$summary = is_file(CONTENT_EVIDENCE_PATH . 'implementation-summary.md')
    ? (string) file_get_contents(CONTENT_EVIDENCE_PATH . 'implementation-summary.md')
    : '';
foreach (
    [
        'exactly 20',
        'all 18 Content Access operations',
        '12 Content',
        'exactly the six',
        'never republishes implicitly',
    ] as $marker
) {
    if (!str_contains($summary, $marker)) {
        $errors[] = "implementation summary is missing marker: {$marker}";
    }
}

$tests = is_file(CONTENT_EVIDENCE_PATH . 'tests.md')
    ? (string) file_get_contents(CONTENT_EVIDENCE_PATH . 'tests.md')
    : '';
foreach (
    [
        'composer install --no-interaction --prefer-dist',
        'composer validate --strict --no-check-publish',
        'scripts/lint.php',
        'vendor/bin/phpstan analyse',
        '--exclude-group=mysql',
        '188 tests, 1321 assertions',
        'complete package `quality:gate`',
        'git diff --check',
    ] as $marker
) {
    if (!str_contains($tests, $marker)) {
        $errors[] = "tests evidence is missing marker: {$marker}";
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "Content Model Administration API v1 evidence contract is valid.\n";
