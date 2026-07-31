<?php

declare(strict_types=1);

use Larena\Content\Access\ContentAccessOperationCatalog;
use Larena\Content\Audit\ContentAuditEventCatalog;
use Larena\Content\Database\ContentOwnedTableShapeGuard;
use Larena\Content\Database\SiteStructureTableShapeGuard;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Rest\Registry\PackageApiContractLoader;
use Symfony\Component\Yaml\Yaml;

const PACKAGE = 'larena/content';
const SPECS_COMMIT = 'f13cb540b2bb3c658ee760816b1539c9ebb616dc';
const BASE_COMMIT = 'a67f44d30f4878b606245ac79d700b1a63606008';
const LAUNCH_RECORD = 'docs/project-management/launch-records/first-run-site-wizard-v1.json';
const EVIDENCE_PATH = 'docs/project-management/evidence/cms-operator-content-admin/content/';

$errors = [];

/**
 * @param list<string> $errors
 * @return array<string, mixed>
 */
function json_object(string $path, array &$errors): array
{
    if (!is_file($path)) {
        $errors[] = "Missing required file: {$path}";
        return [];
    }
    try {
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        $errors[] = "Invalid JSON {$path}: {$exception->getMessage()}";
        return [];
    }
    if (!is_array($value) || array_is_list($value)) {
        $errors[] = "JSON file must contain an object: {$path}";
        return [];
    }
    return $value;
}

/**
 * @param list<string> $errors
 * @return array<string, mixed>
 */
function yaml_object(string $path, array &$errors): array
{
    if (!is_file($path)) {
        $errors[] = "Missing required file: {$path}";
        return [];
    }
    try {
        $value = Yaml::parseFile($path);
    } catch (Throwable $exception) {
        $errors[] = "Invalid YAML {$path}: {$exception->getMessage()}";
        return [];
    }
    if (!is_array($value) || array_is_list($value)) {
        $errors[] = "YAML file must contain a mapping: {$path}";
        return [];
    }
    return $value;
}

foreach ([
    '.larena/spec-ref.json', '.larena/launch-context.json', 'composer.json', 'composer.lock',
    'module.yaml', 'access.yaml', 'audit.yaml', 'api.yaml',
    'database/migrations/2026_07_19_000001_create_larena_content_tables.php',
    'src/Providers/ContentServiceProvider.php', 'src/Services/DatabaseContentTypeService.php',
    'src/Services/DatabaseContentItemService.php', 'src/Runtime/PublishedContentProjectionBuilder.php',
    'src/Services/DatabaseCmsSitePackService.php',
    'src/Services/DatabaseSiteStructureService.php',
    'src/Services/DatabaseManagedContentRedirectReader.php',
    'database/migrations/2026_07_28_000001_create_larena_content_site_structure_tables.php',
    'routes/public.php',
    'routes/admin.php',
    'config/admin.php',
    'resources/views/admin/site-structure.blade.php',
    'resources/views/admin/types/index.blade.php',
    'resources/views/admin/types/create.blade.php',
    'resources/views/admin/materials/index.blade.php',
    'resources/views/admin/materials/form.blade.php',
    'resources/views/admin/materials/preview.blade.php',
    'resources/views/public/page.blade.php',
    'src/Http/Controllers/ContentAdminController.php',
    'src/Http/Controllers/SiteStructureAdminController.php',
    'src/Http/Controllers/PublishedSiteMetadataController.php',
    'src/Navigation/ContentAdminNavigationContributor.php',
    'tests/Feature/ContentAdminRouteTest.php',
    'tests/Feature/SiteStructureRuntimeTest.php',
    'tests/Feature/CmsSitePackPortabilityRuntimeTest.php',
    'tests/Integration/CmsSitePackPortabilityMySqlTest.php',
    'tests/Integration/SiteStructureMigrationShapeTest.php',
    'docs/project-management/launch-records/first-run-site-wizard-v1.json',
    'src/Contracts/StarterSiteInitializer.php',
    'src/FirstRun/ContentFirstRunContributor.php',
    'src/FirstRun/ContentStarterSiteService.php',
    'tests/Contract/ContentFirstRunContributorContractTest.php',
] as $required) {
    if (!is_file($required)) {
        $errors[] = "Missing CMS content model v1 file: {$required}";
    }
}

require_once dirname(__DIR__).'/vendor/autoload.php';

$spec = json_object('.larena/spec-ref.json', $errors);
$context = json_object('.larena/launch-context.json', $errors);
$composer = json_object('composer.json', $errors);
$lock = json_object('composer.lock', $errors);
$module = yaml_object('module.yaml', $errors);

foreach ([
    'package' => PACKAGE,
    'specs_commit' => SPECS_COMMIT,
] as $key => $expected) {
    if (($spec[$key] ?? null) !== $expected) {
        $errors[] = ".larena/spec-ref.json {$key} must equal {$expected}.";
    }
}
if (($spec['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = 'Canonical Specs writes must remain disabled from the package repository.';
}

foreach ([
    'package' => PACKAGE,
    'launch_record_ref' => LAUNCH_RECORD,
    'specs_commit' => SPECS_COMMIT,
    'base_commit' => BASE_COMMIT,
    'branch' => 'main',
    'evidence_path' => EVIDENCE_PATH,
] as $key => $expected) {
    if (($context[$key] ?? null) !== $expected) {
        $errors[] = "launch-context {$key} must equal {$expected}.";
    }
}
if (($context['coding_allowed'] ?? null) !== true || ($context['coding_started'] ?? null) !== true) {
    $errors[] = 'Launch context must explicitly authorize and record coding.';
}
if (($context['review_completed'] ?? null) !== true || ($context['independent_review_verdict'] ?? null) !== 'accepted_with_nonclaims') {
    $errors[] = 'Operational HTTP acceptance must retain the tester verdict and nonclaims.';
}
if (($context['action_gate']['status'] ?? null) !== 'success') {
    $errors[] = 'The package action gate must be successful.';
}
$gateRef = $context['action_gate']['evidence_ref'] ?? null;
if (!is_string($gateRef) || $gateRef === '' || str_ends_with($gateRef, '/pending.json')) {
    $errors[] = 'The successful action gate must point to its durable report.';
}

$requiredFeatures = ['content.type.browser_admin', 'content.material.browser_admin', 'content.editorial_workflow.browser_admin', 'content.public_page', 'content.first_run_starter_site'];
if (($context['selected_features'] ?? null) !== $requiredFeatures) {
    $errors[] = 'Launch context must contain the exact CMS operator Content feature set.';
}

$expectedRevisions = $context['dependency_revisions'] ?? null;
if (!is_array($expectedRevisions) || array_is_list($expectedRevisions)) {
    $errors[] = 'Launch context must contain exact dependency revisions.';
    $expectedRevisions = [];
}
$repositoryRevisions = [];
foreach (($composer['repositories'] ?? []) as $repository) {
    $package = $repository['package'] ?? null;
    $name = is_array($package) ? ($package['name'] ?? null) : null;
    $reference = is_array($package) ? ($package['source']['reference'] ?? null) : null;
    if (is_string($name) && is_string($reference)) {
        $repositoryRevisions[$name] = $reference;
    }
}
$lockRevisions = [];
foreach (($lock['packages'] ?? []) as $package) {
    $name = is_array($package) ? ($package['name'] ?? null) : null;
    $reference = is_array($package) ? ($package['source']['reference'] ?? null) : null;
    if (is_string($name) && is_string($reference)) {
        $lockRevisions[$name] = $reference;
    }
}
foreach ($expectedRevisions as $name => $reference) {
    if (!is_string($name) || !is_string($reference) || preg_match('/^[0-9a-f]{40}$/D', $reference) !== 1) {
        $errors[] = 'Dependency revisions must be full lowercase Git hashes.';
        continue;
    }
    if (($repositoryRevisions[$name] ?? null) !== $reference || ($lockRevisions[$name] ?? null) !== $reference) {
        $errors[] = "Composer metadata does not pin {$name} to {$reference}.";
    }
}

foreach (['larena/access', 'larena/audit', 'larena/auth', 'larena/core', 'larena/dataview', 'larena/filesystem', 'larena/property', 'larena/rest', 'larena/search', 'larena/storage'] as $dependency) {
    if (($composer['require'][$dependency] ?? null) !== 'dev-main') {
        $errors[] = "composer.json must declare direct package-owned dependency {$dependency}.";
    }
}

if (count(ContentAccessOperationCatalog::operations()) !== 29) {
    $errors[] = 'Content must expose the exact 29-operation protected Access catalog.';
}
if (count(ContentAuditEventCatalog::types()) !== 23) {
    $errors[] = 'Content must expose the exact 23-event sanitized Audit catalog.';
}
$api = (new PackageApiContractLoader())->loadFile('api.yaml', PACKAGE);
if (count($api->operations) !== 31) {
    $errors[] = 'Content admin API must compile exactly 31 operations.';
}
if (ContentOwnedTableShapeGuard::tableNames() !== [
    'larena_content_types', 'larena_content_type_versions', 'larena_content_items',
    'larena_content_item_revisions', 'larena_content_item_revision_attachments', 'larena_content_routes',
]) {
    $errors[] = 'Content must retain the exact six-table owned persistence boundary.';
}
if (SiteStructureTableShapeGuard::tableNames() !== [
    'larena_content_site_structures', 'larena_content_site_structure_revisions', 'larena_content_redirects',
]) {
    $errors[] = 'Content must expose the exact three-table site-structure persistence boundary.';
}
$types = [];
foreach (PropertyTypeRegistry::builtIns()->descriptors() as $descriptor) {
    $types[] = $descriptor->typeKey;
}
foreach (['string', 'text', 'number', 'boolean', 'date', 'file', 'relation'] as $type) {
    if (!in_array($type, $types, true)) {
        $errors[] = "Property registry does not provide required CMS field type {$type}.";
    }
}
foreach (['production_ready', 'frontend_ready', 'frontend_complete', 'all_packages_ready', 'all_42_packages_ready'] as $claim) {
    if (($module['nonclaims'][$claim] ?? null) !== false) {
        $errors[] = "module.yaml must keep {$claim}=false.";
    }
}
if (($context['status'] ?? null) !== 'package_verification_passed') {
    $errors[] = 'Launch context must record package HTTP verification.';
}
if (($module['status'] ?? null) !== 'implementation_verification_ready' || ($module['batch'] ?? null) !== 'first-run-site-wizard-v1') {
    $errors[] = 'module.yaml must identify the active first-run site wizard batch.';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_values(array_unique($errors))).PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Larena Content site-structure HTTP contract is valid.'.PHP_EOL);
