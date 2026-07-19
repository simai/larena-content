<?php

declare(strict_types=1);

namespace Larena\Content\Access;

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Runtime\PersistentGlobalRoleQueryScopeProvider;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;

final readonly class ContentAuthorizer
{
    /**
     * Storage repeats these checks independently at its owner boundary.
     *
     * @var array<string, list<string>>
     */
    private const STORAGE_OPERATIONS = [
        'content.type.create' => ['storage.schema.create'],
        'content.item.create' => ['storage.record.create'],
        'content.item.update' => ['storage.record.update'],
        'content.item.restore' => ['storage.record.read', 'storage.record.update'],
        'content.attachment.attach' => [],
        'content.attachment.detach' => [],
        'content.attachment.reorder' => [],
        'content.item.publish' => [],
        'content.item.unpublish' => [],
    ];

    /** @var array<string, string> */
    private const LIST_RESOURCES = [
        'content.type.list' => 'content.type',
        'content.item.list' => 'content.item',
        'content.revision.list' => 'content.revision',
        'content.attachment.list' => 'content.attachment',
    ];

    public function __construct(
        private ActorOperationAuthorizer $operations,
        private PersistentGlobalRoleQueryScopeProvider $queryScopes,
    ) {
    }

    /**
     * @param list<string> $storageOperations
     */
    public function assertAllowed(
        ActorContext $actor,
        string $operation,
        array $storageOperations = [],
    ): void {
        $this->assertActor($actor);
        $this->assertContentOperation($operation);

        $requiredStorageOperations = self::requiredStorageOperations($operation);
        if ($storageOperations !== [] && $storageOperations !== $requiredStorageOperations) {
            throw new ContentRejected(
                'storage_authorization_map_invalid',
                'The requested Storage grants do not match the frozen Content authorization map.',
            );
        }

        // Access owns access.operation.denied. Never catch and duplicate it.
        $this->operations->assertAllowed($actor->actorRef, $operation);

        foreach ($requiredStorageOperations as $storageOperation) {
            $this->operations->assertAllowed($actor->actorRef, $storageOperation);
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function scope(
        array $query,
        ActorContext $actor,
        string $operation,
        string $resourceType,
    ): array {
        $this->assertActor($actor);
        $expectedResourceType = self::LIST_RESOURCES[$operation] ?? null;

        if ($expectedResourceType === null || $resourceType !== $expectedResourceType) {
            throw new ContentRejected(
                'content_query_scope_invalid',
                'Content list scope must use its exact registered resource type.',
            );
        }

        return $this->queryScopes->scope(
            $query,
            $actor->actorRef,
            $operation,
            ['resource_type' => $resourceType],
        );
    }

    /**
     * @return list<string>
     */
    public static function requiredStorageOperations(string $operation): array
    {
        return self::STORAGE_OPERATIONS[$operation] ?? [];
    }

    private function assertContentOperation(string $operation): void
    {
        if (!ContentAccessOperationCatalog::contains($operation)) {
            throw new ContentRejected(
                'content_operation_unknown',
                'The protected Content operation is not registered.',
            );
        }
    }

    private function assertActor(ActorContext $actor): void
    {
        if (
            $actor->actorType !== 'user'
            || preg_match('/\Auser:admin_identity:[1-9][0-9]*\z/D', $actor->actorRef) !== 1
        ) {
            throw new ContentRejected(
                'actor_invalid',
                'Content protected operations require a canonical administrator identity.',
            );
        }
    }
}
