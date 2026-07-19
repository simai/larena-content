<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Larena\Access\Persistence\AdminIdentitySubjectDirectory;
use Larena\Access\Persistence\PersistentAccessStore;
use Larena\Access\Runtime\AccessMutationAuditor;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\Runtime\PersistentActorOperationAuthorizer;
use Larena\Access\Runtime\PersistentGlobalRoleQueryScopeProvider;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DatabaseAuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Sinks\DatabaseAuditSink;
use Larena\Content\Access\ContentAccessOperationCatalog;
use Larena\Content\Access\ContentAuthorizer;
use Larena\Content\Audit\ContentAuditEmitter;
use Larena\Content\Contracts\ContentClock;
use Larena\Content\Contracts\ContentIdGenerator;
use Larena\Content\Dataview\DefaultContentDataviewSourceFactory;
use Larena\Content\Filesystem\FilesystemContentLogicalFileInspector;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\Runtime\PublishedContentProjectionBuilder;
use Larena\Content\Search\DatabaseContentSearchSourceProvider;
use Larena\Content\Services\DatabaseContentItemService;
use Larena\Content\Services\DatabaseContentTypeService;
use Larena\Content\Services\DatabasePublishedContentReader;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Filesystem\Persistence\DatabasePersistentLogicalFileInspector;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Storage\Runtime\VersionedStorage;
use ReflectionClass;
use RuntimeException;

/**
 * Real, file-backed composition of the Content runtime and all seven exact
 * owner packages. The harness intentionally avoids a Laravel application so
 * the integration boundary can be exercised without provider magic.
 */
final class ContentRuntimeHarness
{
    public const string PUBLIC_FILE = '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2';

    public const string SECOND_PUBLIC_FILE = '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e4';

    public const string PRIVATE_FILE = '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e6';

    public readonly Connection $connection;

    public readonly DatabaseContentRepository $repository;

    public readonly DatabaseContentTypeService $types;

    public readonly DatabaseContentItemService $items;

    public readonly DatabasePublishedContentReader $published;

    public readonly DatabaseSearchIndex $searchIndex;

    public readonly DatabaseContentSearchSourceProvider $searchSource;

    public readonly DefaultContentDataviewSourceFactory $dataview;

    public readonly VersionedStorage $ownerStorage;

    public readonly ContentStorageGateway $storage;

    public readonly PersistentAccessStore $accessStore;

    public readonly PersistentActorOperationAuthorizer $ownerAuthorizer;

    public readonly ActorContext $admin;

    public readonly ActorContext $reader;

    private readonly ContentTestDatabase $database;

    private readonly string $blobRoot;

    private int $correlationSequence = 0;

    private bool $closed = false;

    /** @param array<string, mixed>|null $connectionConfig */
    private function __construct(
        private readonly string $path,
        private readonly bool $ownsResources,
        bool $initialize,
        ?array $connectionConfig = null,
        ?string $blobRoot = null,
    ) {
        $this->database = $connectionConfig === null
            ? ContentTestDatabase::fileBackedSqlite($path)
            : ContentTestDatabase::fromConfig($connectionConfig);
        $this->connection = $this->database->connection();
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $this->blobRoot = $blobRoot ?? $path.'.files';

        if ($initialize) {
            $this->migrateOwnersAndContent();
            $this->createAdminIdentityTable();
        }

        $registry = $this->operationRegistry();
        $this->accessStore = new PersistentAccessStore($this->connection);

        if ($initialize) {
            (new SystemRolePresetSynchronizer($registry, $this->accessStore))
                ->synchronizeForLifecycle();
            $this->accessStore->assignRole(
                'user:admin_identity:1',
                'administrator',
                'content-runtime-fixture',
            );
            $this->accessStore->assignRole(
                'user:admin_identity:2',
                'reader',
                'user:admin_identity:1',
            );
        } else {
            (new SystemRolePresetSynchronizer($registry, $this->accessStore))
                ->verifySynchronized();
        }

        $auditPipeline = new AuditEventPipeline(
            new DefaultAuditRedactor(),
            [new DatabaseAuditSink($this->connection)],
        );
        $subjects = new AdminIdentitySubjectDirectory($this->connection);
        $this->ownerAuthorizer = new PersistentActorOperationAuthorizer(
            $this->accessStore,
            $registry,
            $subjects,
            new AccessMutationAuditor($auditPipeline),
        );
        $queryScopes = new PersistentGlobalRoleQueryScopeProvider(
            $registry,
            $this->accessStore,
            $subjects,
            $this->ownerAuthorizer,
        );

        $propertyTypes = PropertyTypeRegistry::builtIns();
        $this->ownerStorage = new VersionedStorage(
            $this->connection,
            $propertyTypes,
            $this->ownerAuthorizer,
            $auditPipeline,
        );
        $this->searchIndex = new DatabaseSearchIndex($this->connection);
        $contentAuditPipeline = new DatabaseAuditEventPipeline($this->connection);
        $clock = new ContentFixtureClock();
        $canonicalJson = new ContentCanonicalJson();
        $input = new ContentInputGuard($canonicalJson);
        $schemas = new ContentSchemaMapper($propertyTypes, $input, $canonicalJson);
        $this->storage = new ContentStorageGateway(
            $this->ownerStorage,
            $schemas,
            $input,
        );
        $contentAuthorizer = new ContentAuthorizer(
            $this->ownerAuthorizer,
            $queryScopes,
        );
        $filesystems = new ContentFixtureFilesystems($this->blobRoot);
        $resolver = new ContentFixtureConnectionResolver($this->connection);
        $ownerFiles = new DatabasePersistentLogicalFileInspector(
            $resolver,
            $filesystems,
        );
        $files = new FilesystemContentLogicalFileInspector($ownerFiles, $input);
        $contentAudit = new ContentAuditEmitter($contentAuditPipeline, $clock);
        $participants = new ContentParticipantGuard(
            $this->connection,
            $this->ownerStorage,
            $this->searchIndex,
            $contentAuditPipeline,
        );
        $projections = new PublishedContentProjectionBuilder(
            $this->storage,
            $files,
        );
        $this->repository = new DatabaseContentRepository($this->connection);
        $this->types = new DatabaseContentTypeService(
            $this->repository,
            $contentAuthorizer,
            $participants,
            $this->storage,
            $schemas,
            $input,
            $canonicalJson,
            $contentAudit,
            $clock,
        );
        $this->items = new DatabaseContentItemService(
            $this->repository,
            $contentAuthorizer,
            $participants,
            $this->storage,
            $schemas,
            $input,
            $files,
            $projections,
            $this->searchIndex,
            $contentAudit,
            $clock,
            new ContentFixtureIdGenerator(),
        );
        $this->published = new DatabasePublishedContentReader(
            $this->repository,
            $participants,
            $this->storage,
            $schemas,
            $projections,
        );
        $this->searchSource = new DatabaseContentSearchSourceProvider(
            $this->repository,
            $this->published,
            $participants,
        );
        $this->dataview = new DefaultContentDataviewSourceFactory($this->items);
        $this->admin = new ActorContext(
            'user',
            'user:admin_identity:1',
            'content-runtime-admin',
        );
        $this->reader = new ActorContext(
            'user',
            'user:admin_identity:2',
            'content-runtime-reader',
        );
    }

    public static function create(): self
    {
        $path = tempnam(sys_get_temp_dir(), 'larena-content-runtime-');
        if (!is_string($path)) {
            throw new RuntimeException('content_runtime_tempfile_failed');
        }

        return new self($path, true, true);
    }

    public static function reopen(string $path, bool $ownsResources = false): self
    {
        if (!is_file($path)) {
            throw new RuntimeException('content_runtime_database_missing');
        }

        return new self($path, $ownsResources, false);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, string $blobRoot): self
    {
        if (
            ($config['driver'] ?? null) !== 'mysql'
            || !is_string($config['database'] ?? null)
            || $config['database'] === ''
        ) {
            throw new RuntimeException('content_runtime_external_config_invalid');
        }

        return new self(
            'mysql:'.$config['database'],
            false,
            true,
            $config,
            $blobRoot,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromExistingConfig(array $config, string $blobRoot): self
    {
        if (
            ($config['driver'] ?? null) !== 'mysql'
            || !is_string($config['database'] ?? null)
            || $config['database'] === ''
        ) {
            throw new RuntimeException('content_runtime_external_config_invalid');
        }

        return new self(
            'mysql:'.$config['database'],
            false,
            false,
            $config,
            $blobRoot,
        );
    }

    public function databasePath(): string
    {
        return $this->path;
    }

    public function actor(int $identity = 1, ?string $correlationId = null): ActorContext
    {
        $this->correlationSequence++;

        return new ActorContext(
            'user',
            'user:admin_identity:'.$identity,
            $correlationId ?? sprintf(
                'content-runtime-%d-%04d',
                $identity,
                $this->correlationSequence,
            ),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function insertFile(
        string $logicalRef,
        array $overrides = [],
        bool $writeBlob = true,
    ): void {
        $storageKey = 'larena/media/blobs/'.str_replace('-', '', $logicalRef);
        $now = '2026-07-19 12:00:00.000000';
        $row = array_replace([
            'logical_ref' => $logicalRef,
            'public_id' => self::publicIdFor($logicalRef),
            'display_name' => 'Content fixture '.$logicalRef,
            'original_name' => 'content-fixture.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size_bytes' => 17,
            'sha256' => str_repeat('a', 64),
            'storage_disk' => 'local',
            'storage_key' => $storageKey,
            'visibility' => 'public',
            'lifecycle_class' => 'persistent',
            'alt_text' => 'Content fixture',
            'created_by' => 1,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $this->connection->table('larena_files')->insert($row);

        if ($writeBlob) {
            $absolute = $this->blobRoot.'/'.$storageKey;
            $directory = dirname($absolute);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException('content_runtime_blob_directory_failed');
            }
            if (file_put_contents($absolute, 'content fixture') === false) {
                throw new RuntimeException('content_runtime_blob_write_failed');
            }
        }
    }

    public function contentAuditCount(?string $eventType = null): int
    {
        $query = $this->connection
            ->table('larena_audit_events')
            ->where('source_package', 'larena/content');

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        return (int) $query->count();
    }

    public function accessDenialCount(): int
    {
        return (int) $this->connection
            ->table('larena_audit_events')
            ->where('event_type', 'access.operation.denied')
            ->count();
    }

    public function close(bool $deleteOwnedResources = true): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->database->close();

        if (!$this->ownsResources || !$deleteOwnedResources) {
            return;
        }

        foreach ([$this->path, $this->path.'-wal', $this->path.'-shm', $this->path.'-journal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        self::removeTree($this->blobRoot);
    }

    private function migrateOwnersAndContent(): void
    {
        foreach ([
            [PersistentActorOperationAuthorizer::class, 'database/migrations/2026_07_10_000001_create_larena_access_role_tables.php'],
            [PersistentActorOperationAuthorizer::class, 'database/migrations/2026_07_12_000001_add_custom_role_fields_to_larena_access_roles.php'],
            [PersistentActorOperationAuthorizer::class, 'database/migrations/2026_07_13_000002_expand_larena_access_subject_roles_to_many.php'],
            [DatabaseAuditEventPipeline::class, 'database/migrations/2026_07_09_000001_create_larena_audit_events_table.php'],
            [VersionedStorage::class, 'database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php'],
            [VersionedStorage::class, 'database/migrations/2026_07_14_000001_validate_larena_storage_version_table_shapes.php'],
            [VersionedStorage::class, 'database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php'],
            [DatabaseSearchIndex::class, 'database/migrations/2026_07_13_000001_create_larena_search_documents.php'],
            [DatabaseSearchIndex::class, 'database/migrations/2026_07_13_000002_create_larena_search_source_states.php'],
            [DatabaseSearchIndex::class, 'database/migrations/2026_07_13_000003_create_larena_search_reindex_runs.php'],
            [DatabaseSearchIndex::class, 'database/migrations/2026_07_13_000004_create_larena_search_provider_states.php'],
            [DatabasePersistentLogicalFileInspector::class, 'database/migrations/2026_07_10_000002_create_larena_files_table.php'],
            [DatabasePersistentLogicalFileInspector::class, 'database/migrations/2026_07_19_000003_add_lifecycle_class_to_larena_files_table.php'],
        ] as [$ownerClass, $relativePath]) {
            $migration = require self::packageRoot($ownerClass).'/'.$relativePath;
            $migration->up();
        }

        $this->database->migrateUp();
    }

    private function createAdminIdentityTable(): void
    {
        $this->connection->getSchemaBuilder()->create(
            'larena_admin_identities',
            static function (Blueprint $table): void {
                $table->id();
                $table->string('status', 32);
                $table->timestamp('disabled_at')->nullable();
            },
        );
        $this->connection->table('larena_admin_identities')->insert([
            ['id' => 1, 'status' => 'active', 'disabled_at' => null],
            ['id' => 2, 'status' => 'active', 'disabled_at' => null],
        ]);
    }

    private function operationRegistry(): AccessOperationRegistry
    {
        $registry = new AccessOperationRegistry();
        $registry->register(new AccessOperationDescriptor(
            code: 'access.role.assign',
            ownerPackage: 'larena/access',
            labelKey: 'larena-access::operations.role_assign',
            target: 'access.role:all',
            requiredGrant: 'assign',
            risk: 'critical',
            auditDenials: true,
        ));

        foreach (ContentAccessOperationCatalog::operations() as $operation) {
            $registry->register($operation);
        }

        foreach ([
            ['storage.schema.create', 'schema_create', 'create', 'critical'],
            ['storage.schema.version', 'schema_version', 'version', 'critical'],
            ['storage.schema_migration.diff', 'schema_migration_diff', 'diff', 'high'],
            ['storage.schema_migration.plan', 'schema_migration_plan', 'plan', 'critical'],
            ['storage.schema_migration.dispatch', 'schema_migration_dispatch', 'dispatch', 'critical'],
            ['storage.schema_migration.explain', 'schema_migration_explain', 'read', 'high'],
            ['storage.record.create', 'record_create', 'create', 'high'],
            ['storage.record.read', 'record_read', 'read', 'high'],
            ['storage.record.update', 'record_update', 'update', 'high'],
        ] as [$code, $label, $grant, $risk]) {
            $registry->register(new AccessOperationDescriptor(
                code: $code,
                ownerPackage: 'larena/storage',
                labelKey: 'larena-storage::operations.'.$label,
                target: str_starts_with($code, 'storage.schema.')
                    || str_starts_with($code, 'storage.schema_migration.')
                        ? 'storage.schema:all'
                        : 'storage.record:all',
                requiredGrant: $grant,
                risk: $risk,
                auditDenials: true,
            ));
        }

        return $registry;
    }

    /**
     * @param class-string $class
     */
    private static function packageRoot(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        if (!is_string($file)) {
            throw new RuntimeException('content_runtime_owner_source_missing');
        }

        return dirname($file, 3);
    }

    private static function publicIdFor(string $logicalRef): string
    {
        $suffix = substr(str_replace('-', '', $logicalRef), -12);

        return '028f62c6-9d27-7d19-b9b1-'.$suffix;
    }

    private static function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }
}

final class ContentFixtureClock implements ContentClock
{
    private int $sequence = 0;

    public function now(): DateTimeImmutable
    {
        $value = new DateTimeImmutable(
            '2026-07-19T12:00:00.000000+00:00',
            new DateTimeZone('UTC'),
        );
        $current = $value->modify(sprintf('+%d microseconds', $this->sequence));
        $this->sequence++;

        return $current;
    }
}

final class ContentFixtureIdGenerator implements ContentIdGenerator
{
    private int $sequence = 1;

    public function newItemRef(): ContentItemRef
    {
        $uuid = sprintf(
            '118f62c6-9d27-4d19-89b1-%012d',
            $this->sequence,
        );
        $this->sequence++;

        return ContentItemRef::fromUuid($uuid);
    }
}

final readonly class ContentFixtureConnectionResolver implements ConnectionResolverInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function connection($name = null): Connection
    {
        return $this->connection;
    }

    public function getDefaultConnection(): string
    {
        return 'default';
    }

    public function setDefaultConnection($name): void
    {
    }
}

final readonly class ContentFixtureFilesystems implements Filesystems
{
    public function __construct(private string $root)
    {
    }

    public function disk($name = null): Filesystem
    {
        if ($name !== 'local') {
            throw new RuntimeException('content_runtime_disk_unknown');
        }

        return new ContentFixtureDisk($this->root);
    }
}

final readonly class ContentFixtureDisk implements Filesystem
{
    public function __construct(private string $root)
    {
    }

    public function exists($path): bool
    {
        return is_file($this->root.'/'.$path);
    }

    public function path($path): string
    {
        return $this->root.'/'.$path;
    }

    public function get($path): ?string
    {
        $value = file_get_contents($this->path($path));

        return $value === false ? null : $value;
    }

    /** @return resource|null */
    public function readStream($path)
    {
        $stream = fopen($this->path($path), 'rb');

        return is_resource($stream) ? $stream : null;
    }

    public function put($path, $contents, $options = []): bool
    {
        return file_put_contents($this->path($path), (string) $contents) !== false;
    }

    /**
     * @param mixed $file
     * @param array<string, mixed> $options
     */
    public function putFile($path, $file = null, $options = []): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }

    /**
     * @param mixed $file
     * @param mixed $name
     * @param array<string, mixed> $options
     */
    public function putFileAs($path, $file, $name = null, $options = []): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }

    /** @param array<string, mixed> $options */
    public function writeStream($path, $resource, array $options = []): bool
    {
        $target = fopen($this->path($path), 'wb');
        if ($target === false) {
            return false;
        }
        $copied = stream_copy_to_stream($resource, $target);
        fclose($target);

        return $copied !== false;
    }

    public function getVisibility($path): string
    {
        return Filesystem::VISIBILITY_PRIVATE;
    }

    public function setVisibility($path, $visibility): bool
    {
        return true;
    }

    public function prepend($path, $data): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }

    public function append($path, $data): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }

    /** @param string|list<string> $paths */
    public function delete($paths): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }

    public function copy($from, $to): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }

    public function move($from, $to): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }

    public function size($path): int
    {
        $size = filesize($this->path($path));

        return $size === false ? 0 : $size;
    }

    public function lastModified($path): int
    {
        $time = filemtime($this->path($path));

        return $time === false ? 0 : $time;
    }

    public function files($directory = null, $recursive = false): array
    {
        return [];
    }

    public function allFiles($directory = null): array
    {
        return [];
    }

    public function directories($directory = null, $recursive = false): array
    {
        return [];
    }

    public function allDirectories($directory = null): array
    {
        return [];
    }

    public function makeDirectory($path): bool
    {
        return mkdir($this->path($path), 0777, true);
    }

    public function deleteDirectory($directory): never
    {
        throw new \BadMethodCallException('content_runtime_fixture_disk_read_only');
    }
}
