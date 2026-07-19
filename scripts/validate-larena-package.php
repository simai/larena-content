<?php

declare(strict_types=1);

const PACKAGE = 'larena/content';
const SPECS_COMMIT = '790ba64b651dc47d416417262a385c26f097bbc7';
const BASE_COMMIT = 'be61840e9efce7ffe291dc16ae4475e1d6d2981a';
const CODING_BRANCH = 'codex/content-platform-v1-interface-contracts';

$requiredFiles = [
    '.gitignore',
    '.env.example',
    '.github/workflows/larena-package-ci.yml',
    '.githooks/pre-commit',
    '.githooks/pre-push',
    '.larena/spec-ref.json',
    '.larena/launch-context.json',
    'access.yaml',
    'audit.yaml',
    'search.yaml',
    'composer.json',
    'composer.lock',
    'module.yaml',
    'phpstan.neon.dist',
    'phpunit.xml.dist',
    'tools/larena-scope-check.php',
    'src/Contracts/ContentTypeService.php',
    'src/Contracts/ContentItemService.php',
    'src/Contracts/PublishedContentReader.php',
    'src/Contracts/ContentSearchSourceProvider.php',
    'src/Contracts/ContentDataviewSourceProvider.php',
    'src/Contracts/ContentLogicalFileInspector.php',
    'src/Access/ContentAccessOperationCatalog.php',
    'src/Audit/ContentAuditEventCatalog.php',
    'src/Search/ContentSearchContract.php',
    'src/Dataview/ContentDataviewContract.php',
    'tests/TestCase.php',
];
$errors = [];

foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $errors[] = "Missing required Content interface file: {$file}";
    }
}

/**
 * @param list<string> $errors
 *
 * @return array<string, mixed>
 */
function read_json_file(string $path, array &$errors): array
{
    if (!is_file($path)) {
        return [];
    }

    try {
        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $exception) {
        $errors[] = "{$path} is invalid JSON: {$exception->getMessage()}";

        return [];
    }

    if (!is_array($decoded)) {
        $errors[] = "{$path} must decode to an object.";

        return [];
    }

    return $decoded;
}

$specRef = read_json_file('.larena/spec-ref.json', $errors);
$launchContext = read_json_file('.larena/launch-context.json', $errors);
$composer = read_json_file('composer.json', $errors);
$lock = read_json_file('composer.lock', $errors);

if (($specRef['package'] ?? null) !== PACKAGE) {
    $errors[] = '.larena/spec-ref.json package must be larena/content.';
}
if (($specRef['specs_commit'] ?? null) !== SPECS_COMMIT) {
    $errors[] = '.larena/spec-ref.json must point to the accepted interface launch Specs commit.';
}
if (($specRef['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = '.larena/spec-ref.json must keep canonical_update_allowed=false.';
}

if (($launchContext['package'] ?? null) !== PACKAGE) {
    $errors[] = '.larena/launch-context.json package must be larena/content.';
}
if (($launchContext['specs_commit'] ?? null) !== SPECS_COMMIT) {
    $errors[] = 'launch-context specs_commit does not match the accepted launch commit.';
}
if (($launchContext['base_commit'] ?? null) !== BASE_COMMIT) {
    $errors[] = 'launch-context base_commit drifted from the accepted clean baseline.';
}
if (($launchContext['branch'] ?? null) !== CODING_BRANCH) {
    $errors[] = 'launch-context branch must be the exact interface-first coding branch.';
}
if (($launchContext['coding_started'] ?? null) !== true) {
    $errors[] = 'coding_started must be true after the accepted interface launch transition.';
}
if (!in_array(
    $launchContext['status'] ?? null,
    ['coding_started', 'review_ready', 'interface_checkpoint_accepted'],
    true,
)) {
    $errors[] = 'launch-context status is outside the interface-first lifecycle.';
}

$expectedFeatures = [
    'content.type_registry',
    'content.item_lifecycle',
    'content.revision_history',
    'content.slug_routing',
    'content.attachment_binding',
    'content.publication_projection',
    'content.search_projection',
];
if (($launchContext['selected_features'] ?? null) !== $expectedFeatures) {
    $errors[] = 'launch-context selected_features must contain the exact seven frozen Content features.';
}

$actionGate = $launchContext['action_gate'] ?? [];
$actionGatePath = is_array($actionGate) ? ($actionGate['evidence_ref'] ?? null) : null;
if (!is_array($actionGate) || ($actionGate['status'] ?? null) !== 'success') {
    $errors[] = 'package action gate must be successful.';
} elseif (!is_string($actionGatePath) || !is_file($actionGatePath)) {
    $errors[] = 'package action gate evidence_ref must resolve to the local ignored report.';
} else {
    $actionGateReport = read_json_file($actionGatePath, $errors);
    if (($actionGateReport['status'] ?? null) !== 'success') {
        $errors[] = 'package action gate report does not have status=success.';
    }
    if (($actionGateReport['repo'] ?? null) !== dirname(__DIR__)) {
        $errors[] = 'package action gate report belongs to a different repository.';
    }
}

$runtimeToolchain = $launchContext['runtime_toolchain'] ?? [];
$runtimeReportPath = is_array($runtimeToolchain) ? ($runtimeToolchain['report_ref'] ?? null) : null;
if (!is_array($runtimeToolchain) || ($runtimeToolchain['status'] ?? null) !== 'ok') {
    $errors[] = 'runtime toolchain must have status=ok.';
} elseif (
    !is_string($runtimeReportPath)
    || !is_file($runtimeReportPath)
) {
    $errors[] = 'runtime toolchain report_ref must resolve to an exact report.';
} else {
    $runtimeReport = read_json_file($runtimeReportPath, $errors);
    if (($runtimeReport['status'] ?? null) !== 'ok') {
        $errors[] = 'runtime toolchain report does not have status=ok.';
    }
    if (($runtimeReport['selected_php']['satisfies_php83'] ?? null) !== true) {
        $errors[] = 'runtime toolchain report does not prove PHP 8.3 or newer.';
    }
}

$expectedDirectDependencies = [
    'larena/access',
    'larena/audit',
    'larena/dataview',
    'larena/filesystem',
    'larena/property',
    'larena/search',
    'larena/storage',
];
foreach ($expectedDirectDependencies as $dependency) {
    if (($composer['require'][$dependency] ?? null) !== 'dev-main') {
        $errors[] = "composer require must declare {$dependency}=dev-main; exact revision belongs to composer.lock.";
    }
}

$expectedLockedRevisions = [
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
$lockedPackages = array_merge(
    is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
    is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
);
$actualLockedRevisions = [];
foreach ($lockedPackages as $package) {
    if (!is_array($package) || !isset($package['name'])) {
        continue;
    }
    $actualLockedRevisions[(string) $package['name']] = $package['source']['reference'] ?? null;
}
foreach ($expectedLockedRevisions as $dependency => $revision) {
    if (($actualLockedRevisions[$dependency] ?? null) !== $revision) {
        $errors[] = "composer.lock revision mismatch for {$dependency}.";
    }
}

$forbiddenPaths = [
    'config',
    'database',
    'routes',
    'resources',
    'lang',
    'src/Commands',
    'src/Database',
    'src/Http',
    'src/Models',
    'src/Persistence',
    'src/Providers',
    'src/Runtime',
    'src/Services',
];
foreach ($forbiddenPaths as $path) {
    if (file_exists($path)) {
        $errors[] = "{$path} is forbidden in the interface-first batch.";
    }
}

$evidencePath = (string) ($launchContext['evidence_path'] ?? '');
$graphProposalPath = (string) ($launchContext['graph_sync_proposal_path'] ?? '');
if (!str_starts_with($evidencePath, 'docs/project-management/evidence/')) {
    $errors[] = 'launch-context evidence_path must start with docs/project-management/evidence/.';
}
if (!str_starts_with($graphProposalPath, $evidencePath)) {
    $errors[] = 'graph_sync_proposal_path must be inside evidence_path.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "Larena Content interface-first package contract is valid.\n";
