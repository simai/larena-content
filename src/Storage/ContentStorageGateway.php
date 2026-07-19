<?php

declare(strict_types=1);

namespace Larena\Content\Storage;

use Illuminate\Database\ConnectionInterface;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Storage\Contracts\StoragePublicProjection;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWriteResult;
use Larena\Storage\Contracts\VersionedStorage;

final readonly class ContentStorageGateway
{
    public function __construct(
        private VersionedStorage $storage,
        private ContentSchemaMapper $schemas,
        private ContentInputGuard $input,
    ) {
    }

    public function connection(): ConnectionInterface
    {
        return $this->storage->connection();
    }

    /**
     * Content Platform v1 creates immutable type schemas only. A non-null
     * expected head would require a separately accepted migration plan.
     *
     * @param list<ContentFieldDefinition> $fields
     */
    public function registerTypeSchema(
        ContentTypeKey $typeKey,
        array $fields,
        ActorContext $actor,
    ): StorageSchemaVersion {
        $this->input->assertActor($actor);
        $this->input->assertFields($fields);
        $schema = $this->storage->registerSchemaVersion(
            $this->schemas->definition($typeKey, $fields),
            null,
            $actor->actorRef,
            $actor->correlationId,
        );

        $this->schemas->assertSchemaMatches($typeKey, $fields, $schema);
        if (
            $schema->ref->version !== 1
            || $schema->createdBy !== $actor->actorRef
            || $schema->correlationId !== $this->ownerCorrelationId(
                $actor->correlationId,
                'storage-schema',
            )
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_write_result_mismatch',
            );
        }

        return $schema;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function create(
        ContentItemRef $itemRef,
        StorageSchemaVersionRef $schema,
        array $values,
        ActorContext $actor,
    ): StorageWriteResult {
        $this->input->assertActor($actor);
        $this->input->assertSubmittedValues($values);
        $result = $this->storage->create(
            $itemRef->value,
            $schema,
            $values,
            $actor->actorRef,
            $actor->correlationId,
        );

        $this->assertWriteResult($result, $itemRef, $schema, $actor, 'create');

        return $result;
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function update(
        ContentItemRef $itemRef,
        StorageRecordVersionRef $expected,
        StorageSchemaVersionRef $schema,
        array $values,
        ActorContext $actor,
    ): StorageWriteResult {
        $this->input->assertActor($actor);
        $this->input->assertSubmittedValues($values);
        $this->input->assertCanIncrementRevision($expected->revision);
        $this->assertRecordIdentity($itemRef, $expected);
        $result = $this->storage->compareAndSwap(
            $itemRef->value,
            $expected,
            $schema,
            $values,
            $actor->actorRef,
            $actor->correlationId,
        );

        $this->assertWriteResult($result, $itemRef, $schema, $actor, 'update', $expected);

        return $result;
    }

    /**
     * Reads the exact historical owner version and appends one new draft
     * Storage version through compare-and-swap. Metadata-only Content
     * mutations deliberately have no method on this gateway.
     */
    public function restore(
        ContentItemRef $itemRef,
        StorageRecordVersionRef $expected,
        StorageSchemaVersionRef $expectedSchema,
        StorageRecordVersionRef $restoreFrom,
        StorageSchemaVersionRef $restoreFromSchema,
        ActorContext $actor,
    ): StorageWriteResult {
        $this->input->assertActor($actor);
        $this->input->assertCanIncrementRevision($expected->revision);
        $this->assertRecordIdentity($itemRef, $expected);
        $this->assertRecordIdentity($itemRef, $restoreFrom);

        if (
            $expected->recordId !== $restoreFrom->recordId
            || $expected->schemaId !== $restoreFrom->schemaId
            || $expected->schemaId !== $expectedSchema->schemaId
            || $restoreFrom->schemaId !== $restoreFromSchema->schemaId
            || $expectedSchema->key() !== $restoreFromSchema->key()
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'restore_record_identity_mismatch',
            );
        }

        $target = $this->storage->readAdminVersion($restoreFrom, $actor->actorRef);
        $this->assertReadVersion(
            $target,
            $itemRef,
            $restoreFrom,
            $restoreFromSchema,
            $actor,
        );
        $this->input->assertNormalizedValues($target->values);

        $result = $this->storage->compareAndSwap(
            $itemRef->value,
            $expected,
            $target->schema,
            $target->values,
            $actor->actorRef,
            $actor->correlationId,
        );

        $this->assertWriteResult(
            $result,
            $itemRef,
            $target->schema,
            $actor,
            'update',
            $expected,
        );

        return $result;
    }

    public function schemaVersion(StorageSchemaVersionRef $ref): StorageSchemaVersion
    {
        $schema = $this->storage->schemaVersion($ref);

        if (
            $schema->ref->key() !== $ref->key()
            || $schema->ownerPackage !== 'larena/content'
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_read_result_mismatch',
            );
        }

        return $schema;
    }

    public function readAdminVersion(
        StorageRecordVersionRef $ref,
        ActorContext $actor,
    ): StorageRecordVersion {
        $this->input->assertActor($actor);
        $version = $this->storage->readAdminVersion($ref, $actor->actorRef);
        $this->input->assertNormalizedValues($version->values);

        if ($version->ref->key() !== $ref->key()) {
            throw new ContentIntegrationFailed(
                'storage',
                'record_read_result_mismatch',
            );
        }

        return $version;
    }

    public function readAdminCurrentVersion(
        string $schemaId,
        ContentItemRef $itemRef,
        ActorContext $actor,
    ): ?StorageRecordVersion {
        $this->input->assertActor($actor);
        $version = $this->storage->readAdminCurrentVersion(
            $schemaId,
            $itemRef->value,
            $actor->actorRef,
        );

        if ($version === null) {
            return null;
        }

        $this->input->assertNormalizedValues($version->values);
        if (
            $version->ref->schemaId !== $schemaId
            || $version->ownerRef !== $itemRef->value
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'record_read_result_mismatch',
            );
        }

        return $version;
    }

    public function publicProjection(StorageRecordVersionRef $ref): StoragePublicProjection
    {
        $projection = $this->storage->projectPublicVersion($ref);
        $this->input->assertNormalizedValues($projection->values);

        if (
            $projection->ref->key() !== $ref->key()
            || $projection->schema->schemaId !== $ref->schemaId
            || preg_match(
                '/\Acontent:item:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',
                $projection->ownerRef,
            ) !== 1
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'public_projection_result_mismatch',
            );
        }

        return $projection;
    }

    private function assertWriteResult(
        StorageWriteResult $result,
        ContentItemRef $itemRef,
        StorageSchemaVersionRef $schema,
        ActorContext $actor,
        string $operation,
        ?StorageRecordVersionRef $expected = null,
    ): void {
        $version = $result->version;
        $this->input->assertNormalizedValues($version->values);
        $this->input->assertMutableRevision($version->ref->revision);
        $expectedRevision = $expected === null ? 1 : $expected->revision + 1;
        $expectedRecordId = $expected?->recordId;

        if (
            $version->ownerRef !== $itemRef->value
            || $version->schema->key() !== $schema->key()
            || $version->ref->schemaId !== $schema->schemaId
            || ($expectedRecordId !== null && $version->ref->recordId !== $expectedRecordId)
            || $version->ref->revision !== $expectedRevision
            || $version->operation !== $operation
            || $version->createdBy !== $actor->actorRef
            || $version->correlationId !== $this->ownerCorrelationId(
                $actor->correlationId,
                'storage-record',
            )
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'record_write_result_mismatch',
            );
        }
    }

    private function assertReadVersion(
        StorageRecordVersion $version,
        ContentItemRef $itemRef,
        StorageRecordVersionRef $ref,
        StorageSchemaVersionRef $schema,
        ActorContext $actor,
    ): void {
        if (
            $version->ref->key() !== $ref->key()
            || $version->ownerRef !== $itemRef->value
            || $version->schema->key() !== $schema->key()
            || $version->schema->schemaId !== $ref->schemaId
            || $version->createdBy === ''
            || $actor->actorRef === ''
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'record_read_result_mismatch',
            );
        }
    }

    private function assertRecordIdentity(
        ContentItemRef $itemRef,
        StorageRecordVersionRef $ref,
    ): void {
        if ($ref->recordId === '' || $itemRef->value === '') {
            throw new ContentIntegrationFailed(
                'storage',
                'record_identity_invalid',
            );
        }
    }

    private function ownerCorrelationId(
        string $callerCorrelationId,
        string $prefix,
    ): string {
        return $prefix . '-' . hash('sha256', $callerCorrelationId);
    }
}
