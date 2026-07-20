<?php

declare(strict_types=1);

namespace Larena\Content\Storage;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Storage\Contracts\StoragePublicProjection;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaCompatibilityReport;
use Larena\Storage\Contracts\StorageSchemaEvolution;
use Larena\Storage\Contracts\StorageSchemaEvolutionTransactionScope;
use Larena\Storage\Contracts\StorageSchemaMigrationPlan;
use Larena\Storage\Contracts\StorageSchemaMigrationResult;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWriteResult;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Throwable;

final readonly class ContentStorageGateway
{
    public function __construct(
        private VersionedStorage $storage,
        private ContentSchemaMapper $schemas,
        private ContentInputGuard $input,
        private ?StorageSchemaEvolution $schemaEvolution = null,
        private ?StorageSchemaEvolutionOwnerPolicyRegistry $ownerPolicies = null,
        private ?ContentStorageSchemaEvolutionAuthority $schemaEvolutionAuthority = null,
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
     * @param list<ContentFieldDefinition> $fields
     */
    public function analyzeTypeSchema(
        ContentTypeKey $typeKey,
        StorageSchemaVersionRef $source,
        array $fields,
        ActorContext $actor,
    ): StorageSchemaCompatibilityReport {
        $this->input->assertActor($actor);
        $this->input->assertFields($fields);
        $evolution = $this->requireSchemaEvolution();

        try {
            $report = $evolution->analyze(
                $source,
                $this->schemas->definition($typeKey, $fields),
                $actor->actorRef,
                $actor->correlationId,
            );
        } catch (StorageRejected $exception) {
            throw new ContentRejected(
                'storage_schema_evolution_rejected',
                'Storage rejected the Content schema candidate.',
                $exception,
            );
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_analyze_failed',
                $exception,
            );
        }

        if (
            $report->source->key() !== $source->key()
            || $report->target->schemaId !== $source->schemaId
            || $report->target->version !== $source->version + 1
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_report_mismatch',
            );
        }

        return $report;
    }

    /**
     * Opens one exact Storage owner-policy transaction scope. The caller must
     * already be inside the shared Content transaction.
     *
     * @template TResult
     * @param Closure(StorageSchemaEvolutionTransactionScope): TResult $operation
     * @return TResult
     */
    public function withinSchemaEvolution(Closure $operation): mixed
    {
        $policies = $this->requireOwnerPolicies();
        $evolution = $this->requireSchemaEvolution();

        if (
            $evolution->connection() !== $this->storage->connection()
            || $evolution->connection()->transactionLevel() < 1
        ) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_transaction_mismatch',
            );
        }

        try {
            return $policies->withinTransaction(
                $evolution->connection(),
                $operation,
            );
        } catch (StorageRejected $exception) {
            throw new ContentRejected(
                'storage_schema_evolution_orchestration_rejected',
                'Storage rejected the Content schema orchestration boundary.',
                $exception,
            );
        }
    }

    /**
     * @param list<ContentFieldDefinition> $fields
     */
    public function planTypeSchema(
        ContentTypeKey $typeKey,
        StorageSchemaCompatibilityReport $report,
        array $fields,
        ActorContext $actor,
        StorageSchemaEvolutionTransactionScope $scope,
    ): StorageSchemaMigrationPlan {
        $this->input->assertActor($actor);
        $this->input->assertFields($fields);
        $evolution = $this->requireSchemaEvolution();
        $authority = $this->requireSchemaEvolutionAuthority();

        try {
            return $authority->withinCapability(
                operation: 'plan',
                actor: $actor->actorRef,
                source: $report->source,
                sourceHash: $report->sourceHash,
                targetHash: $report->targetHash,
                planRef: null,
                planHash: null,
                scope: $scope,
                connection: $evolution->connection(),
                callback: fn (object $capability): StorageSchemaMigrationPlan => $evolution->plan(
                    $report->source,
                    $this->schemas->definition($typeKey, $fields),
                    $actor->actorRef,
                    $actor->correlationId,
                    $scope,
                    $capability,
                ),
            );
        } catch (StorageRejected $exception) {
            throw new ContentRejected(
                str_contains($exception->reasonCode, 'conflict')
                    || str_contains($exception->reasonCode, 'stale')
                    ? 'type_version_conflict'
                    : 'storage_schema_evolution_rejected',
                'Storage rejected the Content schema migration plan.',
                $exception,
            );
        } catch (ContentRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_plan_failed',
                $exception,
            );
        }
    }

    public function applyTypeSchema(
        StorageSchemaMigrationPlan $plan,
        ActorContext $actor,
        StorageSchemaEvolutionTransactionScope $scope,
    ): StorageSchemaMigrationResult {
        $this->input->assertActor($actor);
        $evolution = $this->requireSchemaEvolution();
        $authority = $this->requireSchemaEvolutionAuthority();

        try {
            return $authority->withinCapability(
                operation: 'apply',
                actor: $actor->actorRef,
                source: $plan->source,
                sourceHash: $plan->sourceHash,
                targetHash: $plan->targetHash,
                planRef: $plan->planRef,
                planHash: $plan->planHash,
                scope: $scope,
                connection: $evolution->connection(),
                callback: fn (object $capability): StorageSchemaMigrationResult => $evolution->apply(
                    $plan->planRef,
                    $plan->planHash,
                    $actor->actorRef,
                    $actor->correlationId,
                    $scope,
                    $capability,
                ),
            );
        } catch (StorageRejected $exception) {
            throw new ContentRejected(
                str_contains($exception->reasonCode, 'conflict')
                    || str_contains($exception->reasonCode, 'stale')
                    ? 'type_version_conflict'
                    : 'storage_schema_evolution_rejected',
                'Storage rejected the Content schema migration apply.',
                $exception,
            );
        } catch (ContentRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_apply_failed',
                $exception,
            );
        }
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
            || $expectedSchema->schemaId !== $restoreFromSchema->schemaId
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

        $currentSchema = $this->schemaVersion($expectedSchema);
        $currentFields = $this->schemas->fieldDefinitions($currentSchema);
        $normalized = $this->schemas->normalizeValues(
            $currentFields,
            $target->values,
        );

        $result = $this->storage->compareAndSwap(
            $itemRef->value,
            $expected,
            $expectedSchema,
            $normalized,
            $actor->actorRef,
            $actor->correlationId,
        );

        $this->assertWriteResult(
            $result,
            $itemRef,
            $expectedSchema,
            $actor,
            'update',
            $expected,
        );

        return $result;
    }

    private function requireSchemaEvolution(): StorageSchemaEvolution
    {
        if (!$this->schemaEvolution instanceof StorageSchemaEvolution) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_unavailable',
            );
        }

        return $this->schemaEvolution;
    }

    private function requireOwnerPolicies(): StorageSchemaEvolutionOwnerPolicyRegistry
    {
        if (!$this->ownerPolicies instanceof StorageSchemaEvolutionOwnerPolicyRegistry) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_owner_policy_unavailable',
            );
        }

        return $this->ownerPolicies;
    }

    private function requireSchemaEvolutionAuthority(): ContentStorageSchemaEvolutionAuthority
    {
        if (!$this->schemaEvolutionAuthority instanceof ContentStorageSchemaEvolutionAuthority) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_evolution_authority_unavailable',
            );
        }

        return $this->schemaEvolutionAuthority;
    }

    public function schemaVersion(StorageSchemaVersionRef $ref): StorageSchemaVersion
    {
        try {
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
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_version_read_failed',
                $exception,
            );
        }
    }

    public function readAdminVersion(
        StorageRecordVersionRef $ref,
        ActorContext $actor,
    ): StorageRecordVersion {
        $this->input->assertActor($actor);
        try {
            $version = $this->storage->readAdminVersion($ref, $actor->actorRef);
            $this->input->assertNormalizedValues($version->values);

            if ($version->ref->key() !== $ref->key()) {
                throw new ContentIntegrationFailed(
                    'storage',
                    'record_read_result_mismatch',
                );
            }

            return $version;
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'record_read_failed',
                $exception,
            );
        }
    }

    public function readAdminCurrentVersion(
        string $schemaId,
        ContentItemRef $itemRef,
        ActorContext $actor,
    ): ?StorageRecordVersion {
        $this->input->assertActor($actor);
        try {
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
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'record_read_failed',
                $exception,
            );
        }
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
