<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Unit;

use Illuminate\Database\ConnectionInterface;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Access\Runtime\PersistentGlobalRoleQueryScopeProvider;
use Larena\Audit\Contracts\ConnectionBoundAuditEventPipeline;
use Larena\Content\Access\ContentAuthorizer;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Filesystem\FilesystemContentLogicalFileInspector;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\Runtime\SystemContentClock;
use Larena\Content\Runtime\SystemContentIdGenerator;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Filesystem\Enums\FileLifecycleClass;
use Larena\Filesystem\Enums\FileVisibility;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWriteResult;
use Larena\Storage\Contracts\VersionedStorage;
use ReflectionClass;

final class ContentOwnerAdapterTest extends TestCase
{
    public function testSystemClockAndIdentifierStayInsideCanonicalContracts(): void
    {
        $now = (new SystemContentClock())->now();
        self::assertSame('+00:00', $now->format('P'));

        $itemRef = (new SystemContentIdGenerator())->newItemRef();
        $uuid = $itemRef->uuid();
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $uuid,
        );
    }

    public function testAuthorizerUsesExactContentAndStorageGrantOrder(): void
    {
        $recording = new RecordingContentAuthorizer();
        $authorizer = new ContentAuthorizer($recording, $this->uninitializedQueryScopes());
        $actor = $this->actor();

        $authorizer->assertAllowed($actor, 'content.item.restore');
        self::assertSame([
            [$actor->actorRef, 'content.item.restore'],
            [$actor->actorRef, 'storage.record.read'],
            [$actor->actorRef, 'storage.record.update'],
        ], $recording->calls);

        $recording->calls = [];
        $authorizer->assertAllowed($actor, 'content.item.publish');
        self::assertSame([
            [$actor->actorRef, 'content.item.publish'],
        ], $recording->calls);
    }

    public function testAccessDenialIsRethrownWithoutAContentDuplicate(): void
    {
        $recording = new RecordingContentAuthorizer('content.item.update');
        $authorizer = new ContentAuthorizer($recording, $this->uninitializedQueryScopes());

        try {
            $authorizer->assertAllowed($this->actor(), 'content.item.update');
            self::fail('Expected the owner Access denial.');
        } catch (AccessMutationRejected $exception) {
            self::assertSame('access_actor_forbidden', $exception->reasonCode);
            self::assertCount(1, $recording->calls);
        }
    }

    public function testSchemaMapperPinsExactPropertyVersionsAndVisibility(): void
    {
        $mapper = new ContentSchemaMapper(PropertyTypeRegistry::builtIns());
        $fields = $this->fields();
        $definition = $mapper->definition(new ContentTypeKey('article'), $fields);

        self::assertSame('content.type.article', $definition['schema_id']);
        self::assertSame('larena/content', $definition['owner_package']);
        self::assertSame([2, 1, 1], array_column($definition['fields'], 'type_version'));
        self::assertSame(
            ['public', 'protected', 'admin'],
            array_column($definition['fields'], 'visibility'),
        );
        self::assertSame(
            ['title' => 'Article', 'count' => 42, 'featured' => true],
            $mapper->normalizeValues($fields, [
                'title' => 'Article',
                'count' => 42,
                'featured' => true,
            ]),
        );

        $schema = new StorageSchemaVersion(
            ref: new StorageSchemaVersionRef('content.type.article', 1),
            ownerPackage: 'larena/content',
            fields: $definition['fields'],
            definitionHash: $mapper->schemaHash($definition),
            createdBy: 'user:admin_identity:9',
            correlationId: 'schema-test',
            createdAt: '2026-07-19T08:00:00.000000Z',
        );
        self::assertEquals($fields, $mapper->fieldDefinitions($schema));
    }

    public function testOptionalNullIsOmittedBeforeThePropertyAndStorageBoundary(): void
    {
        $mapper = new ContentSchemaMapper(PropertyTypeRegistry::builtIns());
        $fields = [
            new ContentFieldDefinition(
                'title',
                'string',
                ContentFieldVisibility::Public,
                true,
            ),
            new ContentFieldDefinition(
                'note',
                'string',
                ContentFieldVisibility::Private,
            ),
        ];

        self::assertSame(
            ['title' => 'Article'],
            $mapper->normalizeValues($fields, [
                'title' => 'Article',
                'note' => null,
            ]),
        );

        $this->expectException(ContentRejected::class);
        $mapper->normalizeValues($fields, [
            'title' => null,
            'note' => 'private',
        ]);
    }

    public function testFilesystemAdapterUsesBareUuidAndExactSafeMetadata(): void
    {
        $logicalRef = '018f62c6-9d27-7d19-b9b1-7cddfbd9a3e2';
        $owner = $this->createMock(PersistentLogicalFileInspector::class);
        $owner->expects(self::once())
            ->method('inspect')
            ->with($logicalRef)
            ->willReturn(PersistentLogicalFileInspection::existing(
                logicalRef: $logicalRef,
                physicalAvailable: true,
                visibility: FileVisibility::Public,
                lifecycleClass: FileLifecycleClass::Persistent,
                safeMetadata: [
                    'public_id' => 'file-public-id',
                    'display_name' => 'Hero',
                    'mime_type' => 'image/png',
                    'extension' => 'png',
                    'size_bytes' => 1024,
                    'alt_text' => '',
                ],
            ));

        $inspection = (new FilesystemContentLogicalFileInspector(
            $owner,
            new ContentInputGuard(),
        ))->inspect($logicalRef);

        self::assertTrue($inspection->available);
        self::assertTrue($inspection->public);
        self::assertTrue($inspection->persistent);
        self::assertTrue($inspection->isContentAttachable());
        self::assertTrue($inspection->isPubliclyProjectable());
        self::assertNotSame([], $inspection->safeMetadata);
        if (!array_key_exists('alt_text', $inspection->safeMetadata)) {
            self::fail('The exact Filesystem metadata shape must contain alt_text.');
        }
        self::assertNull($inspection->safeMetadata['alt_text']);
        self::assertSame([
            'public_id',
            'display_name',
            'mime_type',
            'extension',
            'size_bytes',
            'alt_text',
        ], array_keys($inspection->safeMetadata));
    }

    public function testParticipantGuardUsesStrictConnectionObjectIdentity(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $storage = $this->createStub(VersionedStorage::class);
        $storage->method('connection')->willReturn($connection);
        $audit = $this->createStub(ConnectionBoundAuditEventPipeline::class);
        $audit->method('connection')->willReturn($connection);

        $guard = new ContentParticipantGuard(
            $connection,
            $storage,
            new DatabaseSearchIndex($connection),
            $audit,
        );
        self::assertSame($connection, $guard->assertSharedConnection());

        $other = $this->createStub(ConnectionInterface::class);
        $mismatchedStorage = $this->createStub(VersionedStorage::class);
        $mismatchedStorage->method('connection')->willReturn($other);

        $this->expectException(ContentIntegrationFailed::class);
        (new ContentParticipantGuard(
            $connection,
            $mismatchedStorage,
            new DatabaseSearchIndex($connection),
            $audit,
        ))->assertSharedConnection();
    }

    public function testRestoreReadsHistoricalVersionAndCreatesOneCasVersion(): void
    {
        $actor = $this->actor();
        $itemRef = ContentItemRef::fromUuid('018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1');
        $schema = new StorageSchemaVersionRef('content.type.article', 1);
        $expected = new StorageRecordVersionRef(
            'content.type.article',
            'record-018f62c69d277d19b9b17cddfbd9a3e1',
            3,
        );
        $restoreFrom = new StorageRecordVersionRef(
            'content.type.article',
            'record-018f62c69d277d19b9b17cddfbd9a3e1',
            1,
        );
        $target = new StorageRecordVersion(
            ref: $restoreFrom,
            ownerRef: $itemRef->value,
            schema: $schema,
            values: ['title' => 'Old title'],
            contentHash: str_repeat('a', 64),
            operation: 'create',
            createdBy: 'user:admin_identity:9',
            correlationId: 'old-write',
            createdAt: '2026-07-19T08:00:00.000000Z',
        );
        $written = new StorageWriteResult(new StorageRecordVersion(
            ref: new StorageRecordVersionRef(
                'content.type.article',
                'record-018f62c69d277d19b9b17cddfbd9a3e1',
                4,
            ),
            ownerRef: $itemRef->value,
            schema: $schema,
            values: ['title' => 'Old title'],
            contentHash: str_repeat('b', 64),
            operation: 'update',
            createdBy: $actor->actorRef,
            correlationId: 'storage-record-' . hash('sha256', $actor->correlationId),
            createdAt: '2026-07-19T09:00:00.000000Z',
        ));

        $storage = $this->createMock(VersionedStorage::class);
        $storage->expects(self::once())
            ->method('readAdminVersion')
            ->with($restoreFrom, $actor->actorRef)
            ->willReturn($target);
        $schemaMapper = new ContentSchemaMapper(PropertyTypeRegistry::builtIns());
        $schemaDefinition = $schemaMapper->definition(
            new ContentTypeKey('article'),
            [new ContentFieldDefinition(
                'title',
                'string',
                ContentFieldVisibility::Public,
                true,
            )],
        );
        $storage->expects(self::once())
            ->method('schemaVersion')
            ->with($schema)
            ->willReturn(new StorageSchemaVersion(
                ref: $schema,
                ownerPackage: 'larena/content',
                fields: $schemaDefinition['fields'],
                definitionHash: $schemaMapper->schemaHash($schemaDefinition),
                createdBy: 'user:admin_identity:9',
                correlationId: 'schema-test',
                createdAt: '2026-07-19T08:00:00.000000Z',
            ));
        $storage->expects(self::once())
            ->method('compareAndSwap')
            ->with(
                $itemRef->value,
                $expected,
                $schema,
                ['title' => 'Old title'],
                $actor->actorRef,
                $actor->correlationId,
            )
            ->willReturn($written);

        $gateway = new ContentStorageGateway(
            $storage,
            $schemaMapper,
            new ContentInputGuard(),
        );

        self::assertSame(
            $written,
            $gateway->restore(
                $itemRef,
                $expected,
                $schema,
                $restoreFrom,
                $schema,
                $actor,
            ),
        );
    }

    /**
     * @return list<ContentFieldDefinition>
     */
    private function fields(): array
    {
        return [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('count', 'integer', ContentFieldVisibility::Private, true),
            new ContentFieldDefinition('featured', 'boolean', ContentFieldVisibility::AdminOnly, true),
        ];
    }

    private function actor(): ActorContext
    {
        return new ActorContext('user', 'user:admin_identity:9', 'content-test-correlation');
    }

    private function uninitializedQueryScopes(): PersistentGlobalRoleQueryScopeProvider
    {
        /** @var PersistentGlobalRoleQueryScopeProvider */
        return (new ReflectionClass(PersistentGlobalRoleQueryScopeProvider::class))
            ->newInstanceWithoutConstructor();
    }
}

final class RecordingContentAuthorizer implements ActorOperationAuthorizer
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    public function __construct(private readonly ?string $deniedOperation = null)
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        $this->calls[] = [$actor, $operation];

        if ($operation === $this->deniedOperation) {
            throw new AccessMutationRejected('access_actor_forbidden');
        }
    }
}
