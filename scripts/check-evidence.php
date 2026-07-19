<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function evidenceJson(string $path): array
{
    $decoded = json_decode(
        (string) file_get_contents($path),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (!is_array($decoded)) {
        throw new RuntimeException("Evidence JSON must contain an object: {$path}");
    }

    return $decoded;
}

$context = evidenceJson('.larena/launch-context.json');
$evidencePath = rtrim((string) ($context['evidence_path'] ?? ''), '/') . '/';
$proposalPath = (string) ($context['graph_sync_proposal_path'] ?? '');
$errors = [];

$requiredFiles = [
    'README.md',
    'implementation-summary.md',
    'tests.md',
    'smoke.md',
    'file-map.json',
    'deviations.json',
    'dependency-api-map.json',
    'contract-test-matrix.json',
    'graph-sync-proposal.json',
    'code-review-feedback.md',
    'independent-review.md',
];

foreach ($requiredFiles as $required) {
    if (!is_file($evidencePath . $required)) {
        $errors[] = "Missing evidence file: {$evidencePath}{$required}";
    }
}

if (!is_file($proposalPath)) {
    $errors[] = "Missing graph sync proposal: {$proposalPath}";
} else {
    $proposal = evidenceJson($proposalPath);
    if (($proposal['canonical_update_allowed'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must keep canonical_update_allowed=false';
    }
    if (($proposal['apply_requested'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must remain proposal-only.';
    }
    foreach (['runtime_ready', 'production_ready', 'frontend_ready', 'all_packages_ready'] as $nonclaim) {
        if (($proposal['nonclaims'][$nonclaim] ?? null) !== false) {
            $errors[] = "graph-sync-proposal must keep {$nonclaim}=false.";
        }
    }
}

$jsonFiles = [
    'file-map.json',
    'deviations.json',
    'dependency-api-map.json',
    'contract-test-matrix.json',
    'graph-sync-proposal.json',
];
$jsonEvidence = [];

foreach ($jsonFiles as $jsonFile) {
    $path = $evidencePath . $jsonFile;
    if (!is_file($path)) {
        continue;
    }

    try {
        $jsonEvidence[$jsonFile] = evidenceJson($path);
    } catch (Throwable $exception) {
        $errors[] = "{$path} is invalid: {$exception->getMessage()}";
    }
}

$fileMap = $jsonEvidence['file-map.json'] ?? [];
if (($fileMap['forbidden_runtime_present'] ?? null) !== false) {
    $errors[] = 'file-map must prove forbidden_runtime_present=false.';
}
foreach (['contracts', 'value_objects', 'enums_and_exceptions', 'descriptors', 'tests', 'tooling_and_metadata'] as $section) {
    $paths = $fileMap[$section] ?? null;
    if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
        $errors[] = "file-map section {$section} must be a non-empty list.";
        continue;
    }

    foreach ($paths as $path) {
        if (!is_string($path) || !is_file($path)) {
            $errors[] = "file-map references a missing file in {$section}.";
        }
    }
}

$deviations = $jsonEvidence['deviations.json'] ?? [];
foreach (
    [
        'runtime_scope_expanded',
        'canonical_specs_changed_by_package',
        'production_readiness_claimed',
        'frontend_readiness_claimed',
        'all_packages_readiness_claimed',
    ] as $boundedFlag
) {
    if (($deviations[$boundedFlag] ?? null) !== false) {
        $errors[] = "deviations must keep {$boundedFlag}=false.";
    }
}

$dependencyMap = $jsonEvidence['dependency-api-map.json'] ?? [];
$dependencies = $dependencyMap['dependencies'] ?? null;
$expectedRevisions = [
    'larena/access' => 'a98308d62bf39671c9e02b0f7d82065dd50eaf1f',
    'larena/audit' => '34dbed932a6c1f2e0312f9b8d3642d35c5a8b83c',
    'larena/core' => '46f3bbc8baba0262117bc9b9519713ee21b1d981',
    'larena/dataview' => 'b84e964b4ed78e1ca08a46c88e7651b02744ee47',
    'larena/filesystem' => 'ff0c0e355a9c5a59cd0d9a592dcb84c95fd7fb18',
    'larena/layout' => 'cb5bdadf588cb8480972279bea3888500dbf9d6e',
    'larena/licensing' => '52d1215a25369cca17d5170bbfcae82d1f6c86d2',
    'larena/property' => '92b6e915fc4c85239171dbbff6c3cb15d046cc99',
    'larena/search' => 'e7206b2491991790edd2858c993d142184c749ef',
    'larena/storage' => 'c2b3d03ee0c576a67aaad978dc2943b9e64c1237',
    'larena/ui' => '07fff2579344d7c77a28716a74071fb53f0bbfc9',
];

if (!is_array($dependencies) || count($dependencies) !== 11 || ($dependencyMap['exact_dependency_count'] ?? null) !== 11) {
    $errors[] = 'dependency-api-map must contain the exact eleven-package closure.';
} else {
    foreach ($expectedRevisions as $package => $revision) {
        if (($dependencies[$package]['revision'] ?? null) !== $revision) {
            $errors[] = "dependency-api-map revision mismatch for {$package}.";
        }
    }
}

$contractMatrix = $jsonEvidence['contract-test-matrix.json'] ?? [];
$expectedFeatures = [
    'content.type_registry',
    'content.item_lifecycle',
    'content.revision_history',
    'content.slug_routing',
    'content.attachment_binding',
    'content.publication_projection',
    'content.search_projection',
];
$matrixFeatures = array_keys(
    is_array($contractMatrix['features'] ?? null) ? $contractMatrix['features'] : [],
);
if ($matrixFeatures !== $expectedFeatures) {
    $errors[] = 'contract-test-matrix must cover the exact seven frozen features in order.';
}
if (($contractMatrix['runtime_acceptance_deferred'] ?? null) !== true) {
    $errors[] = 'contract-test-matrix must keep runtime acceptance deferred.';
}

$independentReviewPath = $evidencePath . 'independent-review.md';
if (is_file($independentReviewPath)) {
    $review = (string) file_get_contents($independentReviewPath);
    foreach (['/^Verdict: PASS$/m', '/^P0: 0$/m', '/^P1: 0$/m', '/^P2: [0-9]+$/m'] as $pattern) {
        if (preg_match($pattern, $review) !== 1) {
            $errors[] = 'independent-review must record PASS with numeric P0/P1/P2 and P0=P1=0.';
            break;
        }
    }
}

if (($context['status'] ?? null) !== 'interface_checkpoint_accepted') {
    $errors[] = 'launch-context status must be interface_checkpoint_accepted.';
}
if (($context['review_completed'] ?? null) !== true) {
    $errors[] = 'launch-context review_completed must be true.';
}

foreach ($requiredFiles as $required) {
    $path = $evidencePath . $required;
    if (!is_file($path)) {
        continue;
    }

    $contents = (string) file_get_contents($path);
    foreach (['-----BEGIN PRIVATE KEY-----', 'ghp_', 'github_pat_', 'Authorization: Bearer ', 'BITRIX_WEBHOOK'] as $secretMarker) {
        if (str_contains($contents, $secretMarker)) {
            $errors[] = "{$path} contains a forbidden secret marker.";
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo "Evidence contract is valid for the current repository state.\n";
