<?php

declare(strict_types=1);

namespace Larena\Content\Rest;

use InvalidArgumentException;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Auth\ValueObjects\EntryObject;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentRevisionQuery;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeQuery;
use Larena\Content\ValueObjects\ContentTypeSchemaCompatibilityReport;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Content\ValueObjects\ContentTypeVersionQuery;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Rest\Contracts\OperationContextMetadata;
use Larena\Rest\Contracts\OperationHandlerRegistry;
use Larena\Rest\Exceptions\ApiOperationException;

final readonly class ContentAdminApiOperationHandler
{
    /** @var list<string> */
    private const OPERATION_REFERENCES = [
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

    public function __construct(
        private ContentTypeService $types,
        private ContentItemService $items,
        private ContentAdminReadModel $reads,
        private ContentAdminValueCodec $codec,
    ) {
    }

    /** @param callable():mixed $resolver */
    public static function registerLazy(
        OperationHandlerRegistry $handlers,
        callable $resolver,
    ): void {
        $resolve = static function () use ($resolver): self {
            $candidate = $resolver();
            if (!$candidate instanceof self) {
                throw new InvalidArgumentException(
                    'content_admin_api_handler_resolver_invalid',
                );
            }

            return $candidate;
        };

        foreach (self::OPERATION_REFERENCES as $operationReference) {
            $handlers->register(
                $operationReference,
                static fn (
                    array $input,
                    OperationDescriptor $descriptor,
                    OperationContext $context,
                ): array => $resolve()->dispatch(
                    $operationReference,
                    $input,
                    $descriptor,
                    $context,
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function dispatch(
        string $operationReference,
        array $input,
        OperationDescriptor $descriptor,
        OperationContext $context,
    ): array {
        if ($descriptor->name !== $operationReference) {
            throw new ApiOperationException(
                'content_admin_api_operation_mismatch',
                409,
                'The Content operation declaration does not match its registered handler.',
            );
        }

        $actor = $this->actor($context);
        $path = $this->inputObject($input, 'path');
        $query = $this->inputObject($input, 'query');
        $body = $this->inputObject($input, 'body');

        try {
            return match ($operationReference) {
                'content.type_admin.list' => $this->typeList($query, $actor),
                'content.type_admin.read' => [
                    'type' => $this->currentType(
                        new ContentTypeKey($this->requiredString($path, 'type_key')),
                        $actor,
                    ),
                ],
                'content.type_admin.create' => $this->typeCreate($body, $actor),
                'content.type_admin.versions.list' => $this->typeVersionList(
                    $path,
                    $query,
                    $actor,
                ),
                'content.type_admin.versions.read' => [
                    'version' => $this->codec->encodeTypeVersion(
                        $this->types->version(
                            new ContentTypeKey($this->requiredString($path, 'type_key')),
                            $this->requiredInt($path, 'version'),
                            $actor,
                        ),
                    ),
                ],
                'content.type_admin.versions.preview' => $this->typeVersionPreview(
                    $path,
                    $body,
                    $actor,
                ),
                'content.type_admin.versions.create' => $this->typeVersionCreate(
                    $path,
                    $body,
                    $actor,
                ),
                'content.item_admin.list' => $this->itemList($query, $actor),
                'content.item_admin.read' => [
                    'item' => $this->reads->item(
                        $this->items->read($this->itemRef($path), $actor),
                        $actor,
                    ),
                ],
                'content.item_admin.create' => $this->itemCreate($body, $actor),
                'content.item_admin.update' => $this->itemUpdate($path, $body, $actor),
                'content.item_admin.revisions.list' => $this->revisionList(
                    $path,
                    $query,
                    $actor,
                ),
                'content.item_admin.revisions.read' => [
                    'revision' => $this->reads->revision(
                        $this->items->revision(
                            $this->itemRef($path),
                            $this->requiredInt($path, 'revision'),
                            $actor,
                        ),
                        $actor,
                    ),
                ],
                'content.item_admin.revisions.restore' => $this->itemResult(
                    $this->items->restore(
                        $this->itemRef($path),
                        $this->requiredInt($path, 'revision'),
                        $this->requiredInt($body, 'expected_revision'),
                        $actor,
                    ),
                ),
                'content.item_admin.publish' => $this->itemResult(
                    $this->items->publish(
                        $this->itemRef($path),
                        $this->requiredInt($body, 'expected_revision'),
                        $actor,
                    ),
                ),
                'content.item_admin.unpublish' => $this->itemResult(
                    $this->items->unpublish(
                        $this->itemRef($path),
                        $this->requiredInt($body, 'expected_revision'),
                        $actor,
                    ),
                ),
                'content.item_admin.attachments.list' => $this->attachmentList(
                    $path,
                    $actor,
                ),
                'content.item_admin.attachments.attach' => $this->itemResult(
                    $this->items->attach(
                        $this->itemRef($path),
                        $this->requiredInt($body, 'expected_revision'),
                        $this->requiredString($body, 'logical_file_ref'),
                        $this->requiredString($body, 'role'),
                        $actor,
                    ),
                ),
                'content.item_admin.attachments.detach' => $this->itemResult(
                    $this->items->detach(
                        $this->itemRef($path),
                        $this->requiredInt($body, 'expected_revision'),
                        $this->requiredString($path, 'logical_file_ref'),
                        $this->requiredString($body, 'role'),
                        $actor,
                    ),
                ),
                'content.item_admin.attachments.reorder' => $this->itemResult(
                    $this->items->reorder(
                        $this->itemRef($path),
                        $this->requiredInt($body, 'expected_revision'),
                        $this->codec->placements(
                            $this->requiredList($body, 'attachments'),
                        ),
                        $actor,
                    ),
                ),
                default => throw new ApiOperationException(
                    'content_admin_api_operation_unknown',
                    404,
                    'The Content operation is not registered.',
                ),
            };
        } catch (ApiOperationException $exception) {
            throw $exception;
        } catch (AccessMutationRejected) {
            throw new ApiOperationException(
                'access_denied',
                403,
                'The authenticated actor is not allowed to perform this Content operation.',
            );
        } catch (ContentConflict $exception) {
            throw new ApiOperationException(
                $exception->reasonCode(),
                409,
                'The Content resource changed before the operation could be applied.',
            );
        } catch (ContentRejected $exception) {
            throw $this->domainException($exception);
        } catch (InvalidArgumentException|\ValueError) {
            throw new ApiOperationException(
                'content_admin_input_invalid',
                422,
                'The Content administration input is invalid.',
            );
        } catch (ContentIntegrationFailed $exception) {
            // Rest owns the uniform fail-closed 500 envelope. ApiOperationException
            // deliberately represents only bounded client-visible failures.
            throw new \RuntimeException(
                'content_admin_integration_failed',
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function typeList(array $query, ActorContext $actor): array
    {
        $limit = $this->optionalInt($query, 'limit') ?? 50;
        $after = $this->optionalString($query, 'after_type_key');
        $page = $this->types->list(
            new ContentTypeQuery(
                $after === null ? null : new ContentTypeKey($after),
                $limit,
            ),
            $actor,
        );

        return [
            'items' => array_map(
                static fn ($type): array => [
                    'type_key' => $type->typeKey->value,
                    'current_version' => $type->currentVersion,
                ],
                $page->items,
            ),
            'pagination' => [
                'limit' => $limit,
                'after_type_key' => $after,
                'next_after_type_key' => $page->nextTypeKey?->value,
                'has_more' => $page->nextTypeKey !== null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function currentType(ContentTypeKey $typeKey, ActorContext $actor): array
    {
        $head = $this->types->read($typeKey, $actor);

        return [
            'type_key' => $typeKey->value,
            'current_version' => $head->currentVersion,
            'version' => $this->codec->encodeTypeVersion(
                $this->types->version($typeKey, $head->currentVersion, $actor),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function typeCreate(array $body, ActorContext $actor): array
    {
        $fields = $this->codec->fields($this->requiredList($body, 'fields'));
        $typeKey = new ContentTypeKey($this->requiredString($body, 'type_key'));
        $type = $this->types->create(
            $typeKey,
            $fields,
            $this->codec->projection(
                $this->requiredObject($body, 'projection'),
                $fields,
            ),
            $this->codec->safeMetadata(
                $this->requiredObject($body, 'safe_metadata'),
            ),
            $actor,
        );

        return [
            'type' => [
                'type_key' => $type->typeKey->value,
                'current_version' => $type->currentVersion,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function typeVersionList(
        array $path,
        array $query,
        ActorContext $actor,
    ): array {
        $typeKey = new ContentTypeKey($this->requiredString($path, 'type_key'));
        $limit = $this->optionalInt($query, 'limit') ?? 50;
        $after = $this->optionalInt($query, 'after_version');
        $page = $this->types->versions(
            new ContentTypeVersionQuery($typeKey, $after, $limit),
            $actor,
        );

        return [
            'items' => array_map(
                fn (ContentTypeVersion $version): array => $this->codec->encodeTypeVersion($version),
                $page->items,
            ),
            'pagination' => [
                'limit' => $limit,
                'after_version' => $after,
                'next_after_version' => $page->nextAfterVersion,
                'has_more' => $page->nextAfterVersion !== null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function typeVersionPreview(
        array $path,
        array $body,
        ActorContext $actor,
    ): array {
        $fields = $this->codec->fields($this->requiredList($body, 'fields'));
        $report = $this->types->previewVersion(
            new ContentTypeKey($this->requiredString($path, 'type_key')),
            $this->requiredInt($body, 'expected_version'),
            $fields,
            $this->codec->projection(
                $this->requiredObject($body, 'projection'),
                $fields,
            ),
            $this->codec->safeMetadata(
                $this->requiredObject($body, 'safe_metadata'),
            ),
            $actor,
        );

        return ['compatibility' => $this->compatibility($report)];
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function typeVersionCreate(
        array $path,
        array $body,
        ActorContext $actor,
    ): array {
        $fields = $this->codec->fields($this->requiredList($body, 'fields'));
        $typeKey = new ContentTypeKey($this->requiredString($path, 'type_key'));
        $type = $this->types->createVersion(
            $typeKey,
            $this->requiredInt($body, 'expected_version'),
            $fields,
            $this->codec->projection(
                $this->requiredObject($body, 'projection'),
                $fields,
            ),
            $this->codec->safeMetadata(
                $this->requiredObject($body, 'safe_metadata'),
            ),
            $actor,
        );

        return [
            'type' => [
                'type_key' => $type->typeKey->value,
                'current_version' => $type->currentVersion,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function compatibility(ContentTypeSchemaCompatibilityReport $report): array
    {
        return [
            'type_key' => $report->typeKey->value,
            'source_version' => $report->sourceVersion,
            'source_schema_hash' => $report->sourceSchemaHash,
            'target_version' => $report->targetVersion,
            'target_schema_hash' => $report->targetSchemaHash,
            'compatible' => $report->compatible,
            'compatibility_class' => $report->compatibilityClass,
            'added_optional_field_count' => $report->addedOptionalFieldCount,
            'item_count' => $report->itemCount,
            'item_heads_hash' => $report->itemHeadsHash,
            'storage_record_heads_hash' => $report->storageRecordHeadsHash,
            'reason_codes' => $report->reasonCodes,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function itemList(array $query, ActorContext $actor): array
    {
        $limit = $this->optionalInt($query, 'limit') ?? 50;
        $after = $this->optionalString($query, 'after_item_ref');
        $typeKey = $this->optionalString($query, 'type_key');
        $locale = $this->optionalString($query, 'locale');
        $status = $this->optionalString($query, 'status');
        $visibility = $this->optionalString($query, 'visibility');
        $page = $this->items->list(
            new ContentItemQuery(
                typeKey: $typeKey === null ? null : new ContentTypeKey($typeKey),
                locale: $locale === null ? null : new ContentLocale($locale),
                status: $status === null ? null : ContentStatus::from($status),
                visibility: $visibility === null ? null : ContentVisibility::from($visibility),
                afterItemRef: $after === null ? null : new ContentItemRef($after),
                limit: $limit,
            ),
            $actor,
        );

        return [
            'items' => array_map(
                fn (ContentItem $item): array => $this->reads->itemSummary($item),
                $page->items,
            ),
            'pagination' => [
                'limit' => $limit,
                'after_item_ref' => $after,
                'next_after_item_ref' => $page->nextItemRef?->value,
                'has_more' => $page->nextItemRef !== null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function itemCreate(array $body, ActorContext $actor): array
    {
        return $this->itemResult(
            $this->items->create(
                new ContentTypeKey($this->requiredString($body, 'type_key')),
                new ContentLocale($this->requiredString($body, 'locale')),
                new ContentSlug($this->requiredString($body, 'slug')),
                ContentVisibility::from($this->requiredString($body, 'visibility')),
                $this->codec->values($this->requiredList($body, 'values')),
                $actor,
            ),
        );
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function itemUpdate(
        array $path,
        array $body,
        ActorContext $actor,
    ): array {
        return $this->itemResult(
            $this->items->update(
                $this->itemRef($path),
                $this->requiredInt($body, 'expected_revision'),
                new ContentSlug($this->requiredString($body, 'slug')),
                ContentVisibility::from($this->requiredString($body, 'visibility')),
                $this->codec->values($this->requiredList($body, 'values')),
                $actor,
            ),
        );
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function revisionList(
        array $path,
        array $query,
        ActorContext $actor,
    ): array {
        $itemRef = $this->itemRef($path);
        $limit = $this->optionalInt($query, 'limit') ?? 50;
        $after = $this->optionalInt($query, 'after_revision');
        $page = $this->items->revisions(
            new ContentRevisionQuery($itemRef, $after, $limit),
            $actor,
        );

        return [
            'items' => array_map(
                fn ($revision): array => $this->reads->revisionSummary($revision),
                $page->items,
            ),
            'pagination' => [
                'limit' => $limit,
                'after_revision' => $after,
                'next_after_revision' => $page->nextRevision,
                'has_more' => $page->nextRevision !== null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $path
     * @return array<string, mixed>
     */
    private function attachmentList(array $path, ActorContext $actor): array
    {
        $attachments = $this->items->currentAttachments(
            $this->itemRef($path),
            $actor,
        );

        return [
            'item_ref' => $attachments->itemRef->value,
            'revision' => $attachments->revision,
            'items' => array_map(
                fn ($attachment): array => $this->reads->attachment($attachment),
                $attachments->items,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function itemResult(ContentItem $item): array
    {
        return ['item' => $this->reads->itemSummary($item)];
    }

    /** @param array<string, mixed> $path */
    private function itemRef(array $path): ContentItemRef
    {
        return new ContentItemRef($this->requiredString($path, 'item_ref'));
    }

    private function actor(OperationContext $context): ActorContext
    {
        $entryObject = $context->metadata[OperationContextMetadata::AUTHENTICATED_ACTOR] ?? null;
        if (!$entryObject instanceof EntryObject || $entryObject->subjectRef !== $context->actorId) {
            throw new ApiOperationException(
                'content_admin_api_session_context_invalid',
                403,
                'A validated administrator session context is required.',
            );
        }

        return new ActorContext('user', $context->actorId, $context->correlationId);
    }

    private function domainException(ContentRejected $exception): ApiOperationException
    {
        $reason = $exception->reasonCode();
        $status = match (true) {
            in_array($reason, [
                'type_not_found',
                'type_version_not_found',
                'item_not_found',
                'revision_not_found',
                'attachment_not_found',
            ], true) => 404,
            str_contains($reason, 'stale'),
            str_contains($reason, 'conflict'),
            in_array($reason, [
                'type_already_exists',
                'type_version_no_change',
                'item_not_published',
                'private_item_cannot_publish',
                'attachment_already_exists',
            ], true) => 409,
            default => 422,
        };

        return new ApiOperationException(
            $reason,
            $status,
            $status === 404
                ? 'The requested Content resource was not found.'
                : ($status === 409
                    ? 'The Content operation conflicts with the current resource state.'
                    : 'The Content operation input is invalid.'),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function inputObject(array $input, string $location): array
    {
        $value = $input[$location] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ApiOperationException(
                'content_admin_api_request_invalid',
                422,
                'The validated Content request input is malformed.',
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('content_admin_api_request_invalid');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function optionalString(array $input, string $key): ?string
    {
        return array_key_exists($key, $input)
            ? $this->requiredString($input, $key)
            : null;
    }

    /** @param array<string, mixed> $input */
    private function requiredInt(array $input, string $key): int
    {
        $value = $input[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('content_admin_api_request_invalid');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function optionalInt(array $input, string $key): ?int
    {
        return array_key_exists($key, $input)
            ? $this->requiredInt($input, $key)
            : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function requiredObject(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('content_admin_api_request_invalid');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function requiredList(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('content_admin_api_request_invalid');
        }

        $list = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw new InvalidArgumentException('content_admin_api_request_invalid');
            }
            $list[] = $entry;
        }

        return $list;
    }
}
