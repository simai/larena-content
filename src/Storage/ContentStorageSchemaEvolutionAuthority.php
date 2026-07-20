<?php

declare(strict_types=1);

namespace Larena\Content\Storage;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSchemaEvolutionTransactionScope;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use RuntimeException;
use WeakMap;

/**
 * Issues single-use, in-memory capabilities for the Content-owned Storage
 * schema. The authority is deliberately captured by provider closures rather
 * than exposed as a public container service.
 *
 * @internal
 */
final class ContentStorageSchemaEvolutionAuthority
{
    /** @var WeakMap<ContentStorageSchemaEvolutionCapability, ContentStorageSchemaEvolutionClaim> */
    private WeakMap $capabilities;

    public function __construct()
    {
        $this->capabilities = new WeakMap();
    }

    /**
     * @template TResult
     * @param Closure(ContentStorageSchemaEvolutionCapability): TResult $callback
     * @return TResult
     */
    public function withinCapability(
        string $operation,
        string $actor,
        StorageSchemaVersionRef $source,
        string $sourceHash,
        string $targetHash,
        ?string $planRef,
        ?string $planHash,
        StorageSchemaEvolutionTransactionScope $scope,
        ConnectionInterface $connection,
        Closure $callback,
    ): mixed {
        if (
            $connection->transactionLevel() < 1
            || !in_array($operation, ['plan', 'apply'], true)
        ) {
            throw new RuntimeException('content_storage_schema_evolution_authority_invalid');
        }

        $capability = new ContentStorageSchemaEvolutionCapability();
        $this->capabilities[$capability] = new ContentStorageSchemaEvolutionClaim(
            operation: $operation,
            actor: $actor,
            source: $source,
            sourceHash: $sourceHash,
            targetHash: $targetHash,
            planRef: $planRef,
            planHash: $planHash,
            scope: $scope,
            connection: $connection,
        );

        try {
            return $callback($capability);
        } finally {
            unset($this->capabilities[$capability]);
        }
    }

    /** @return Closure(StorageSchemaEvolutionOwnerContext, ?object): void */
    public function ownerValidator(): Closure
    {
        return function (
            StorageSchemaEvolutionOwnerContext $context,
            ?object $capability,
        ): void {
            if (
                !$capability instanceof ContentStorageSchemaEvolutionCapability
                || !isset($this->capabilities[$capability])
            ) {
                throw new RuntimeException('content_storage_schema_evolution_capability_invalid');
            }

            $claim = $this->capabilities[$capability];
            unset($this->capabilities[$capability]);

            if (
                $context->connection !== $claim->connection
                || $context->transactionScope !== $claim->scope
                || $context->operation !== $claim->operation
                || !hash_equals($context->actor, $claim->actor)
                || $context->source->key() !== $claim->source->key()
                || !hash_equals($context->sourceHash, $claim->sourceHash)
                || !hash_equals($context->targetHash, $claim->targetHash)
                || $context->planRef !== $claim->planRef
                || $context->planHash !== $claim->planHash
            ) {
                throw new RuntimeException('content_storage_schema_evolution_capability_invalid');
            }
        };
    }
}

/** @internal */
final class ContentStorageSchemaEvolutionCapability
{
}

/** @internal */
final readonly class ContentStorageSchemaEvolutionClaim
{
    public function __construct(
        public string $operation,
        public string $actor,
        public StorageSchemaVersionRef $source,
        public string $sourceHash,
        public string $targetHash,
        public ?string $planRef,
        public ?string $planHash,
        public StorageSchemaEvolutionTransactionScope $scope,
        public ConnectionInterface $connection,
    ) {
    }
}
