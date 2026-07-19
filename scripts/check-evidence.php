<?php

declare(strict_types=1);

const CONTENT_EVIDENCE_SPECS_COMMIT = '2c3c3106a4afb98925dbf8192cfaadb57ca4d4a9';
const CONTENT_EVIDENCE_PATH = 'docs/project-management/evidence/data-content/batch-2/content-guarded-runtime/';

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

/**
 * @param array<mixed> $value
 */
function evidence_contains_secret(array $value): bool
{
    $encoded = json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    return evidence_text_contains_secret($encoded);
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

$errors = [];

try {
    $context = evidence_json('.larena/launch-context.json');
} catch (Throwable $exception) {
    fwrite(STDERR, "Invalid launch context: {$exception->getMessage()}" . PHP_EOL);
    exit(1);
}

$evidencePath = rtrim((string) ($context['evidence_path'] ?? ''), '/') . '/';
$proposalPath = (string) ($context['graph_sync_proposal_path'] ?? '');
if (($context['specs_commit'] ?? null) !== CONTENT_EVIDENCE_SPECS_COMMIT) {
    $errors[] = 'launch-context specs_commit is not the guarded-runtime launch commit.';
}
if ($evidencePath !== CONTENT_EVIDENCE_PATH) {
    $errors[] = 'launch-context evidence_path is not the exact batch-2 path.';
}
if ($proposalPath !== CONTENT_EVIDENCE_PATH . 'graph-sync-proposal.json') {
    $errors[] = 'graph_sync_proposal_path must be the exact batch-2 proposal path.';
}

$requiredFiles = [
    'README.md',
    'implementation-summary.md',
    'tests.md',
    'smoke.md',
    'file-map.json',
    'dependency-api-map.json',
    'sqlite-proof.json',
    'mysql-proof.json',
    'restart-proof.json',
    'migration-rollback.md',
    'migration-rollback.json',
    'atomicity-proof.json',
    'concurrency-proof.json',
    'deviations.json',
    'code-review-feedback.md',
    'independent-review.md',
    'graph-sync-proposal.json',
];
$missingEvidence = false;
foreach ($requiredFiles as $required) {
    if (!is_file($evidencePath . $required)) {
        $missingEvidence = true;
        $errors[] = "Missing evidence file: {$evidencePath}{$required}";
    }
}

if (!$missingEvidence) {
    if (($context['status'] ?? null) !== 'guarded_runtime_checkpoint_accepted') {
        $errors[] = 'launch-context status must be guarded_runtime_checkpoint_accepted.';
    }
    if (($context['review_completed'] ?? null) !== true) {
        $errors[] = 'launch-context review_completed must be true.';
    }
    if (($context['independent_review_verdict'] ?? null) !== 'pass') {
        $errors[] = 'launch-context independent_review_verdict must be pass.';
    }
    $findings = is_array($context['independent_review_findings'] ?? null)
        ? $context['independent_review_findings']
        : [];
    if (
        ($findings['p0'] ?? null) !== 0
        || ($findings['p1'] ?? null) !== 0
        || !is_int($findings['p2'] ?? null)
    ) {
        $errors[] = 'launch-context independent findings must record P0=0, P1=0 and numeric P2.';
    }
}

$jsonFiles = [
    'file-map.json',
    'dependency-api-map.json',
    'sqlite-proof.json',
    'mysql-proof.json',
    'restart-proof.json',
    'migration-rollback.json',
    'atomicity-proof.json',
    'concurrency-proof.json',
    'deviations.json',
    'graph-sync-proposal.json',
];
$jsonEvidence = [];
foreach ($jsonFiles as $jsonFile) {
    $path = $evidencePath . $jsonFile;
    if (!is_file($path)) {
        continue;
    }

    try {
        $document = evidence_json($path);
        if ($document === []) {
            $errors[] = "{$path} must not be an empty object.";
        }
        if (evidence_contains_secret($document)) {
            $errors[] = "{$path} contains a forbidden secret marker.";
        }
        $jsonEvidence[$jsonFile] = $document;
    } catch (Throwable $exception) {
        $errors[] = "{$path} is invalid: {$exception->getMessage()}";
    }
}

$expectedRevisions = [
    'larena/access' => '8c0e75897fe422a8f4d97fc012f1d095ffdba3b2',
    'larena/audit' => 'ab2546b1a0fdd577faba895755a3d6c44f0f9da8',
    'larena/core' => '46f3bbc8baba0262117bc9b9519713ee21b1d981',
    'larena/dataview' => 'b84e964b4ed78e1ca08a46c88e7651b02744ee47',
    'larena/filesystem' => '6c784d0ad84e5fcc72b515c8b5b27bafac9ee31f',
    'larena/layout' => 'cb5bdadf588cb8480972279bea3888500dbf9d6e',
    'larena/licensing' => '52d1215a25369cca17d5170bbfcae82d1f6c86d2',
    'larena/property' => '92b6e915fc4c85239171dbbff6c3cb15d046cc99',
    'larena/search' => 'e7206b2491991790edd2858c993d142184c749ef',
    'larena/storage' => '7645c0124999eeab6150edc0b0b949adc17be310',
    'larena/ui' => '07fff2579344d7c77a28716a74071fb53f0bbfc9',
];
$dependencyMap = $jsonEvidence['dependency-api-map.json'] ?? [];
if ($dependencyMap !== []) {
    $dependencies = is_array($dependencyMap['dependencies'] ?? null)
        ? $dependencyMap['dependencies']
        : [];
    if (
        count($dependencies) !== 11
        || ($dependencyMap['exact_dependency_count'] ?? null) !== 11
        || ($dependencyMap['specs_commit'] ?? null) !== CONTENT_EVIDENCE_SPECS_COMMIT
    ) {
        $errors[] = 'dependency-api-map must identify the exact guarded-runtime eleven-package closure.';
    } else {
        foreach ($expectedRevisions as $package => $revision) {
            if (($dependencies[$package]['revision'] ?? null) !== $revision) {
                $errors[] = "dependency-api-map revision mismatch for {$package}.";
            }
        }
    }
}

$fileMap = $jsonEvidence['file-map.json'] ?? [];
if ($fileMap !== []) {
    if (($fileMap['batch'] ?? null) !== 'batch-2-content-guarded-runtime') {
        $errors[] = 'file-map must identify batch-2-content-guarded-runtime.';
    }
    if (($fileMap['content_owned_table_count'] ?? null) !== 6) {
        $errors[] = 'file-map must record exactly six Content-owned tables.';
    }
    if (($fileMap['dataview_owns_canonical_records'] ?? null) !== false) {
        $errors[] = 'file-map must record Dataview canonical ownership as false.';
    }
}

$deviations = $jsonEvidence['deviations.json'] ?? [];
if ($deviations !== []) {
    foreach (
        [
            'canonical_specs_changed_by_package',
            'production_readiness_claimed',
            'frontend_readiness_claimed',
            'release_or_update_server_readiness_claimed',
            'all_packages_readiness_claimed',
        ] as $boundedFlag
    ) {
        if (($deviations[$boundedFlag] ?? null) !== false) {
            $errors[] = "deviations must keep {$boundedFlag}=false.";
        }
    }
}

$proposal = $jsonEvidence['graph-sync-proposal.json'] ?? [];
if ($proposal !== []) {
    if (($proposal['canonical_update_allowed'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must keep canonical_update_allowed=false.';
    }
    if (($proposal['apply_requested'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must remain proposal-only.';
    }
    if (($proposal['source_specs_commit'] ?? null) !== CONTENT_EVIDENCE_SPECS_COMMIT) {
        $errors[] = 'graph-sync-proposal must point to the guarded-runtime Specs commit.';
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
            $errors[] = "graph-sync-proposal must keep {$nonclaim}=false.";
        }
    }
}

$testsPath = $evidencePath . 'tests.md';
if (is_file($testsPath)) {
    $tests = (string) file_get_contents($testsPath);
    foreach (
        [
            'composer validate --strict',
            'composer run validate:larena',
            'composer run lint',
            'composer run analyse',
            'composer run test:contract',
            'composer run test:unit',
            'composer run test:feature',
            'composer run test:sqlite',
            'composer run test:mysql',
            'composer run test:concurrency',
            'composer run test:http',
            'composer run test:rollback',
            'composer run test',
            'composer run scope:check',
            'git diff --check',
        ] as $requiredCommand
    ) {
        if (!str_contains($tests, $requiredCommand)) {
            $errors[] = "tests.md is missing required command: {$requiredCommand}";
        }
    }
}

$reviewPath = $evidencePath . 'independent-review.md';
if (is_file($reviewPath)) {
    $review = (string) file_get_contents($reviewPath);
    foreach (
        [
            '/^Verdict: PASS$/m',
            '/^P0: 0$/m',
            '/^P1: 0$/m',
            '/^P2: [0-9]+$/m',
        ] as $pattern
    ) {
        if (preg_match($pattern, $review) !== 1) {
            $errors[] = 'independent-review must record PASS with P0=0, P1=0 and numeric P2.';
            break;
        }
    }
}

foreach ($requiredFiles as $required) {
    $path = $evidencePath . $required;
    if (
        is_file($path)
        && !str_ends_with($path, '.json')
        && evidence_text_contains_secret((string) file_get_contents($path))
    ) {
        $errors[] = "{$path} contains a forbidden secret marker.";
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "Evidence contract is valid for the guarded-runtime repository state.\n";
