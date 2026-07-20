<?php

declare(strict_types=1);

use Larena\Content\Access\ContentAccessOperationCatalog;
use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Dataview\ContentDataviewContract;
use Larena\Content\Search\ContentSearchContract;
use Larena\Rest\Registry\PackageApiContractLoader;
use Symfony\Component\Yaml\Yaml;

const PACKAGE = 'larena/content';
const SPECS_COMMIT = 'b5ea1bc2386544d4a6f4e4af4ce172a28988f0be';
const GOVERNING_SPECS_BASE_COMMIT = 'f722a4b1a8833879755d51f8076a761596b84cc3';
const BASE_COMMIT = '4f19197636b3878ac0732f0229cd898291bdd3cc';
const CODING_BRANCH = 'codex/content-model-administration-api-v1';
const LAUNCH_RECORD = 'specs/implementation-planning/content-model-administration-api-v1-contract.json';
const EVIDENCE_PATH = 'docs/project-management/evidence/data-content/content-model-administration-api-v1/';
const ALLOWED_FILES_HASH = '2c5656c081ae153de92ed50d3935f9c3fc2b4da5a79eadd4960fc3dd587e8556';
const FORBIDDEN_FILES_HASH = 'c58334343a8099b59108ac534dbe8ab3042f9b4beef9580aba88bf9dddd7fedf';
const FORBIDDEN_BEHAVIOR_HASH = 'c9a82832d0f43818724ead3d3eb9da9e3b7ce2bac449b9f610d1c416e3ee80cd';

$errors = [];
$requiredFiles = [
    '.larena/spec-ref.json',
    '.larena/launch-context.json',
    'CHANGELOG.md',
    'README.md',
    'access.yaml',
    'api.yaml',
    'audit.yaml',
    'search.yaml',
    'composer.json',
    'composer.lock',
    'module.yaml',
    'phpstan.neon.dist',
    'phpunit.xml.dist',
    'scripts/check-evidence.php',
    'scripts/lint.php',
    'scripts/validate-larena-package.php',
    'database/migrations/2026_07_19_000001_create_larena_content_tables.php',
    'routes/public.php',
    'src/Access/ContentAccessOperationCatalog.php',
    'src/Access/ContentAuthorizer.php',
    'src/Audit/ContentAuditEmitter.php',
    'src/Contracts/ContentDataviewSourceFactory.php',
    'src/Contracts/ContentItemService.php',
    'src/Contracts/ContentSearchSourceProvider.php',
    'src/Contracts/ContentTypeService.php',
    'src/Contracts/PublishedContentReader.php',
    'src/Database/ContentOwnedTableShapeGuard.php',
    'src/Dataview/ContentDataviewContract.php',
    'src/Dataview/DefaultContentDataviewSourceFactory.php',
    'src/Http/Controllers/PublishedContentController.php',
    'src/Persistence/DatabaseContentRepository.php',
    'src/Providers/ContentServiceProvider.php',
    'src/Rest/ContentAdminApiOperationHandler.php',
    'src/Rest/ContentAdminReadModel.php',
    'src/Rest/ContentAdminValueCodec.php',
    'src/Runtime/ContentParticipantGuard.php',
    'src/Runtime/PublishedContentProjectionBuilder.php',
    'src/Search/ContentSearchContract.php',
    'src/Search/ContainerContentSearchSourceFactory.php',
    'src/Search/DatabaseContentSearchSourceProvider.php',
    'src/Services/DatabaseContentItemService.php',
    'src/Services/DatabaseContentTypeService.php',
    'src/Services/DatabasePublishedContentReader.php',
    'src/Storage/ContentStorageGateway.php',
    'src/Storage/ContentStorageSchemaEvolutionAuthority.php',
    'src/ValueObjects/ContentAttachmentPage.php',
    'src/ValueObjects/ContentTypeSchemaCompatibilityReport.php',
    'src/ValueObjects/ContentTypeVersionPage.php',
    'src/ValueObjects/ContentTypeVersionQuery.php',
    'tests/Contract/ContentAdminApiContractTest.php',
    'tests/Feature/ContentDataviewRuntimeTest.php',
    'tests/Feature/ContentProviderBindingTest.php',
    'tests/Feature/ContentSearchRuntimeTest.php',
    'tests/Feature/ContentAdminApiHandlerRuntimeTest.php',
    'tests/Feature/ContentTypeSchemaVersionRuntimeTest.php',
    'tests/Feature/PublicContentHttpTest.php',
    'tests/Feature/PublishedContentHttpTest.php',
    'tests/Integration/ContentMigrationShapeTest.php',
    'tests/Integration/ContentPlatformSQLiteTest.php',
    'tests/Support/ContentRuntimeHarness.php',
    'tests/Support/ContentTestDatabase.php',
    'tests/Unit/ContentAdminHandlerLifetimeTest.php',
    'tests/Unit/ContentAdminValueCodecTest.php',
    'docs/developer/README.md',
    'docs/developer/content-model-administration-api-v1.md',
    'tools/larena-scope-check.php',
];

foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $errors[] = "Missing required guarded-runtime file: {$file}";
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

    if (!is_array($decoded) || array_is_list($decoded)) {
        $errors[] = "{$path} must decode to an object.";

        return [];
    }

    return $decoded;
}

/**
 * @param list<string> $errors
 *
 * @return array<string, mixed>
 */
function read_yaml_file(string $path, array &$errors): array
{
    if (!is_file($path)) {
        return [];
    }

    try {
        $decoded = Yaml::parseFile($path);
    } catch (Throwable $exception) {
        $errors[] = "{$path} is invalid YAML: {$exception->getMessage()}";

        return [];
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        $errors[] = "{$path} must decode to a mapping.";

        return [];
    }

    return $decoded;
}

/**
 * @param array<mixed> $value
 */
function list_hash(array $value): string
{
    return hash(
        'sha256',
        json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ),
    );
}

/**
 * @param array<string, string> $expected
 * @param array<string, mixed> $actual
 * @param list<string> $errors
 */
function compare_string_map(
    array $expected,
    array $actual,
    string $label,
    array &$errors,
): void {
    ksort($expected, SORT_STRING);
    ksort($actual, SORT_STRING);

    if ($actual !== $expected) {
        $errors[] = "{$label} must contain the exact frozen package revision map.";
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $errors[] = 'vendor/autoload.php is required before package validation.';
} else {
    require_once $autoload;
}

$specRef = read_json_file('.larena/spec-ref.json', $errors);
$launchContext = read_json_file('.larena/launch-context.json', $errors);
$composer = read_json_file('composer.json', $errors);
$lock = read_json_file('composer.lock', $errors);

if (($specRef['package'] ?? null) !== PACKAGE) {
    $errors[] = '.larena/spec-ref.json package must be larena/content.';
}
if (($specRef['specs_commit'] ?? null) !== SPECS_COMMIT) {
    $errors[] = '.larena/spec-ref.json must point to the exact API v1 Specs commit.';
}
if (($specRef['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = '.larena/spec-ref.json must keep canonical_update_allowed=false.';
}

$launchExpectations = [
    'package' => PACKAGE,
    'launch_record_ref' => LAUNCH_RECORD,
    'specs_commit' => SPECS_COMMIT,
    'launch_record_specs_base_commit' => GOVERNING_SPECS_BASE_COMMIT,
    'base_commit' => BASE_COMMIT,
    'branch' => CODING_BRANCH,
    'evidence_path' => EVIDENCE_PATH,
];
foreach ($launchExpectations as $field => $expected) {
    if (($launchContext[$field] ?? null) !== $expected) {
        $errors[] = "launch-context {$field} drifted from the API v1 implementation contract.";
    }
}
if (($launchContext['coding_started'] ?? null) !== true) {
    $errors[] = 'guarded-runtime coding_started must be true.';
}
if (($launchContext['status'] ?? null) !== 'implementation_verification_ready') {
    $errors[] = 'launch-context status must be implementation_verification_ready.';
}

$expectedFeatures = [
    'content.type_registry',
    'content.type_schema_versioning',
    'content.item_lifecycle',
    'content.revision_history',
    'content.slug_routing',
    'content.attachment_binding',
    'content.publication_projection',
    'content.search_projection',
    'content.admin_api_v1',
];
if (($launchContext['selected_features'] ?? null) !== $expectedFeatures) {
    $errors[] = 'launch-context must contain the exact nine API v1 Content features.';
}

foreach (
    [
        ['allowed_files', 171, ALLOWED_FILES_HASH],
        ['forbidden_files', 17, FORBIDDEN_FILES_HASH],
        ['forbidden_behavior', 21, FORBIDDEN_BEHAVIOR_HASH],
    ] as [$field, $count, $hash]
) {
    $surface = $launchContext[$field] ?? null;
    if (!is_array($surface) || !array_is_list($surface)) {
        $errors[] = "launch-context {$field} must be an ordered list.";
        continue;
    }
    if (count($surface) !== $count || list_hash($surface) !== $hash) {
        $errors[] = "launch-context {$field} drifted from the exact batch-2 surface.";
    }
}

$allowedFiles = $launchContext['allowed_files'] ?? [];
if (is_array($allowedFiles)) {
    foreach ($requiredFiles as $file) {
        if (
            str_starts_with($file, 'database/')
            || str_starts_with($file, 'routes/')
            || str_starts_with($file, 'src/')
            || str_starts_with($file, 'tests/')
        ) {
            if (!in_array($file, $allowedFiles, true)) {
                $errors[] = "{$file} is outside the exact guarded-runtime allowed surface.";
            }
        }
    }
}

$actionGate = $launchContext['action_gate'] ?? [];
if (!is_array($actionGate) || ($actionGate['status'] ?? null) !== 'not_required') {
    $errors[] = 'local package implementation must record action_gate=not_required.';
}
if (
    ($launchContext['review_completed'] ?? null) !== false
    || ($launchContext['independent_review_verdict'] ?? null) !== 'pending'
) {
    $errors[] = 'package metadata must not pre-author the independent review verdict.';
}

$runtimeToolchain = $launchContext['runtime_toolchain'] ?? [];
$runtimeReportPath = is_array($runtimeToolchain) ? ($runtimeToolchain['report_ref'] ?? null) : null;
if (!is_array($runtimeToolchain) || ($runtimeToolchain['status'] ?? null) !== 'ok') {
    $errors[] = 'runtime toolchain must have status=ok.';
} elseif (
    $runtimeReportPath !== EVIDENCE_PATH . 'tests.md'
    || !is_file($runtimeReportPath)
    || ($runtimeToolchain['php_version'] ?? null) !== '8.3.31'
) {
    $errors[] = 'runtime toolchain must resolve to the current portable API v1 receipt.';
}

$expectedDirectRevisions = [
    'larena/access' => '8c0e75897fe422a8f4d97fc012f1d095ffdba3b2',
    'larena/audit' => 'ab2546b1a0fdd577faba895755a3d6c44f0f9da8',
    'larena/auth' => '63bac556b36a25fe16885601aefe174d5d712c3a',
    'larena/core' => '46f3bbc8baba0262117bc9b9519713ee21b1d981',
    'larena/dataview' => 'b84e964b4ed78e1ca08a46c88e7651b02744ee47',
    'larena/filesystem' => '6c784d0ad84e5fcc72b515c8b5b27bafac9ee31f',
    'larena/property' => '92b6e915fc4c85239171dbbff6c3cb15d046cc99',
    'larena/rest' => '174dc005002a5ba0e77f906d3e9143ce89a5fd2b',
    'larena/search' => '9f5c1cf5d2b112751328520eee34826c19dd2535',
    'larena/storage' => '7645c0124999eeab6150edc0b0b949adc17be310',
];
$launchRevisions = is_array($launchContext['dependency_revisions'] ?? null)
    ? $launchContext['dependency_revisions']
    : [];
compare_string_map(
    $expectedDirectRevisions,
    $launchRevisions,
    'launch-context dependency_revisions',
    $errors,
);
foreach (array_keys($expectedDirectRevisions) as $dependency) {
    if (($composer['require'][$dependency] ?? null) !== 'dev-main') {
        $errors[] = "composer require must declare {$dependency}=dev-main.";
    }
}

$expectedLockedRevisions = $expectedDirectRevisions + [
    'larena/admin' => '540e171625cd6a58e8ced00a085abfb45d9ad781',
    'larena/cockpit' => 'd8074d30727d5c124928b8e47466f063eb746dbf',
    'larena/layout' => 'cb5bdadf588cb8480972279bea3888500dbf9d6e',
    'larena/licensing' => '52d1215a25369cca17d5170bbfcae82d1f6c86d2',
    'larena/link' => 'affc02abad5f3be568ae02c3678abe51d14575a9',
    'larena/ui' => '07fff2579344d7c77a28716a74071fb53f0bbfc9',
    'larena/update' => '4c56bb8d26b6259ae71e58512ccadc2529accfec',
];
$lockedPackages = array_merge(
    is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
    is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
);
$actualLockedRevisions = [];
foreach ($lockedPackages as $package) {
    if (
        is_array($package)
        && is_string($package['name'] ?? null)
        && str_starts_with($package['name'], 'larena/')
    ) {
        $actualLockedRevisions[$package['name']] = $package['source']['reference'] ?? null;
    }
}
compare_string_map(
    $expectedLockedRevisions,
    $actualLockedRevisions,
    'composer.lock',
    $errors,
);

$repositoryRevisions = [];
foreach (($composer['repositories'] ?? []) as $repository) {
    $package = is_array($repository) ? ($repository['package'] ?? null) : null;
    if (
        is_array($package)
        && is_string($package['name'] ?? null)
        && str_starts_with($package['name'], 'larena/')
    ) {
        $repositoryRevisions[$package['name']] = $package['source']['reference'] ?? null;
    }
}
compare_string_map(
    $expectedLockedRevisions,
    $repositoryRevisions,
    'composer package repositories',
    $errors,
);
if (
    ($composer['extra']['laravel']['providers'] ?? null)
    !== ['Larena\\Content\\Providers\\ContentServiceProvider']
) {
    $errors[] = 'composer Laravel discovery must register only ContentServiceProvider.';
}
if (($composer['config']['platform']['php'] ?? null) !== '8.3.31') {
    $errors[] = 'composer platform PHP must remain fixed at 8.3.31.';
}

$expectedTables = [
    'larena_content_types',
    'larena_content_type_versions',
    'larena_content_items',
    'larena_content_item_revisions',
    'larena_content_item_revision_attachments',
    'larena_content_routes',
];
if (class_exists(ContentOwnedTableShapeGuard::class)) {
    if (ContentOwnedTableShapeGuard::tableNames() !== $expectedTables) {
        $errors[] = 'Content must own exactly the six frozen tables in order.';
    }
}
$migrationPath = 'database/migrations/2026_07_19_000001_create_larena_content_tables.php';
$migration = is_file($migrationPath)
    ? (string) file_get_contents($migrationPath)
    : '';
preg_match_all(
    "/Schema::create\\(\\s*['\"]([^'\"]+)['\"]/",
    $migration,
    $migrationMatches,
);
$migrationTables = $migrationMatches[1];
sort($migrationTables, SORT_STRING);
$sortedExpectedTables = $expectedTables;
sort($sortedExpectedTables, SORT_STRING);
if ($migrationTables !== $sortedExpectedTables) {
    $errors[] = 'Content migration must create exactly the six frozen tables.';
}
foreach (['foreignId(', 'foreignUuid(', '->foreign(', '->constrained('] as $foreignKeyMarker) {
    if (str_contains($migration, $foreignKeyMarker)) {
        $errors[] = 'Content migration must not declare cross-owner foreign keys.';
    }
}

if (class_exists(ContentSearchContract::class)) {
    $searchDescriptor = ContentSearchContract::descriptor();
    if (
        $searchDescriptor->providerId !== 'content.published_items'
        || $searchDescriptor->ownerPackage !== PACKAGE
        || $searchDescriptor->accessScope !== 'public'
        || $searchDescriptor->includesPrivatePayload
    ) {
        $errors[] = 'Search descriptor drifted from the rebuildable public Content source.';
    }
}
if (class_exists(ContentDataviewContract::class)) {
    $dataviewDescriptor = ContentDataviewContract::descriptor();
    if (
        $dataviewDescriptor->sourceKey !== 'content.items'
        || $dataviewDescriptor->ownerPackage !== PACKAGE
        || !$dataviewDescriptor->accessScoped
        || $dataviewDescriptor->ownsCanonicalRecords
        || ContentDataviewContract::mutationOperations() !== []
    ) {
        $errors[] = 'Dataview must remain Access-scoped presentation without canonical ownership.';
    }
}
if (class_exists(ContentAccessOperationCatalog::class)) {
    $operationCodes = ContentAccessOperationCatalog::codes();
    if (count($operationCodes) !== 18 || in_array('content.public.read', $operationCodes, true)) {
        $errors[] = 'Content Access catalog must contain 18 protected operations and no public-read grant.';
    }
}

if (class_exists(PackageApiContractLoader::class)) {
    try {
        $apiContract = (new PackageApiContractLoader())->loadFile('api.yaml', PACKAGE);
        $apiOperationKeys = array_map(
            static fn ($operation): string => $operation->operationKey,
            $apiContract->operations,
        );
        $expectedApiOperationKeys = [
            'content.type_admin.list',
            'content.type_admin.read',
            'content.type_admin.create',
            'content.type_admin.versions.list',
            'content.type_admin.versions.read',
            'content.type_admin.versions.preview',
            'content.type_admin.versions.create',
            'content.item_admin.list',
            'content.item_admin.read',
            'content.item_admin.create',
            'content.item_admin.update',
            'content.item_admin.revisions.list',
            'content.item_admin.revisions.read',
            'content.item_admin.revisions.restore',
            'content.item_admin.publish',
            'content.item_admin.unpublish',
            'content.item_admin.attachments.list',
            'content.item_admin.attachments.attach',
            'content.item_admin.attachments.detach',
            'content.item_admin.attachments.reorder',
        ];
        if ($apiOperationKeys !== $expectedApiOperationKeys) {
            $errors[] = 'api.yaml must compile the exact ordered 20-operation API v1 catalog.';
        }
        foreach ($apiContract->operations as $operation) {
            if (
                $operation->handlerReference !== $operation->operationKey
                || !str_starts_with($operation->path, '/api/v1/admin/content')
                || $operation->authChannels !== ['admin_session']
            ) {
                $errors[] = "api.yaml operation {$operation->operationKey} drifted from the closed admin boundary.";
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'api.yaml does not compile through larena/rest: ' . $exception->getMessage();
    }
}

$routeSource = is_file('routes/public.php')
    ? (string) file_get_contents('routes/public.php')
    : '';
if (
    !str_contains($routeSource, "Route::get('/content/{typeKey}/{slug}'")
    || !str_contains($routeSource, "->name('larena.content.public.show')")
) {
    $errors[] = 'Public Content route must use the exact sessionless GET route and stable name.';
}
foreach (['middleware(', 'auth', 'session', 'ActorContext'] as $protectedMarker) {
    if (str_contains($routeSource, $protectedMarker)) {
        $errors[] = 'Public Content route must not acquire protected or session middleware.';
    }
}

$providerSource = is_file('src/Providers/ContentServiceProvider.php')
    ? (string) file_get_contents('src/Providers/ContentServiceProvider.php')
    : '';
foreach (
    [
        'loadMigrationsFrom',
        'loadRoutesFrom',
        'AccessOperationRegistry::class',
        'OperationHandlerRegistry::class',
        'ContentAdminApiOperationHandler::registerLazy',
        'SearchSourceRegistry::class',
        'ContainerContentSearchSourceFactory::class',
        'ContentDataviewSourceFactory::class',
        'PublishedContentReader::class',
    ] as $compositionMarker
) {
    if (!str_contains($providerSource, $compositionMarker)) {
        $errors[] = "ContentServiceProvider is missing {$compositionMarker}.";
    }
}

$module = class_exists(Yaml::class)
    ? read_yaml_file('module.yaml', $errors)
    : [];
if (
    ($module['package'] ?? null) !== PACKAGE
    || ($module['status'] ?? null) !== 'implementation_candidate'
    || ($module['batch'] ?? null) !== 'content-model-administration-api-v1'
    || ($module['features'] ?? null) !== $expectedFeatures
    || ($module['evidence']['path'] ?? null) !== EVIDENCE_PATH
) {
    $errors[] = 'module.yaml must describe the exact API v1 implementation candidate.';
}
foreach (['runtime_ready', 'production_ready', 'frontend_ready', 'all_packages_ready'] as $nonclaim) {
    if (($module['nonclaims'][$nonclaim] ?? null) !== false) {
        $errors[] = "module.yaml must keep {$nonclaim}=false.";
    }
}

$access = class_exists(Yaml::class)
    ? read_yaml_file('access.yaml', $errors)
    : [];
$accessCodes = [];
foreach (($access['operations'] ?? []) as $operation) {
    if (is_array($operation) && is_string($operation['code'] ?? null)) {
        $accessCodes[] = $operation['code'];
    }
}
if (
    ($access['package'] ?? null) !== PACKAGE
    || count($accessCodes) !== 18
    || in_array('content.public.read', $accessCodes, true)
) {
    $errors[] = 'access.yaml must describe the exact protected catalog without public read.';
}

$audit = class_exists(Yaml::class)
    ? read_yaml_file('audit.yaml', $errors)
    : [];
if (
    ($audit['package'] ?? null) !== PACKAGE
    || ($audit['storage_owner'] ?? null) !== 'larena/audit'
    || count(is_array($audit['events'] ?? null) ? $audit['events'] : []) !== 12
) {
    $errors[] = 'audit.yaml must retain the twelve-event larena/audit-owned boundary.';
}

$search = class_exists(Yaml::class)
    ? read_yaml_file('search.yaml', $errors)
    : [];
$searchSource = is_array($search['sources'][0] ?? null) ? $search['sources'][0] : [];
if (
    ($search['package'] ?? null) !== PACKAGE
    || ($search['index_owner'] ?? null) !== 'larena/search'
    || ($searchSource['provider_id'] ?? null) !== 'content.published_items'
    || ($searchSource['access_scope'] ?? null) !== 'public'
    || ($searchSource['includes_private_payload'] ?? null) !== false
) {
    $errors[] = 'search.yaml must retain the exact public rebuildable Search source.';
}

$readme = is_file('README.md') ? (string) file_get_contents('README.md') : '';
foreach (
    [
        SPECS_COMMIT,
        'closed draft administrator API',
        '20 operations',
        'Type evolution',
        'does not claim production readiness',
        EVIDENCE_PATH,
    ] as $readmeMarker
) {
    if (!str_contains($readme, $readmeMarker)) {
        $errors[] = "README.md is missing API v1 marker: {$readmeMarker}";
    }
}
if (str_contains($readme, 'interface-first')) {
    $errors[] = 'README.md must not describe batch-2 as the old interface-first checkpoint.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "Larena Content Model Administration API v1 package contract is valid.\n";
