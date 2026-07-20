<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentAttachmentPlacement;
use Larena\Content\ValueObjects\ContentAttachmentPage;
use Larena\Content\ValueObjects\ContentAttachmentReference;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemPage;
use Larena\Content\ValueObjects\ContentItemQuery;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentRevision;
use Larena\Content\ValueObjects\ContentRevisionPage;
use Larena\Content\ValueObjects\ContentRevisionQuery;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\Enums\ContentVisibility;

interface ContentItemService
{
    /**
     * Typed values cross this mutation boundary and remain owned by
     * larena/storage. They must not be copied into Content read DTOs or Audit.
     *
     * @param array<string, scalar|null> $values
     */
    public function create(
        ContentTypeKey $typeKey,
        ContentLocale $locale,
        ContentSlug $slug,
        ContentVisibility $visibility,
        array $values,
        ActorContext $actor,
    ): ContentItem;

    public function read(ContentItemRef $itemRef, ActorContext $actor): ContentItem;

    public function list(ContentItemQuery $query, ActorContext $actor): ContentItemPage;

    /**
     * @param array<string, scalar|null> $values
     */
    public function update(
        ContentItemRef $itemRef,
        int $expectedRevision,
        ContentSlug $slug,
        ContentVisibility $visibility,
        array $values,
        ActorContext $actor,
    ): ContentItem;

    public function restore(
        ContentItemRef $itemRef,
        int $restoreRevision,
        int $expectedRevision,
        ActorContext $actor,
    ): ContentItem;

    public function revision(
        ContentItemRef $itemRef,
        int $revision,
        ActorContext $actor,
    ): ContentRevision;

    public function revisions(
        ContentRevisionQuery $query,
        ActorContext $actor,
    ): ContentRevisionPage;

    /**
     * Returns no more than ContentRevision::MAX_ATTACHMENTS ordered references.
     *
     * @return list<ContentAttachmentReference>
     */
    public function attachments(
        ContentItemRef $itemRef,
        int $revision,
        ActorContext $actor,
    ): array;

    public function currentAttachments(
        ContentItemRef $itemRef,
        ActorContext $actor,
    ): ContentAttachmentPage;

    public function attach(
        ContentItemRef $itemRef,
        int $expectedRevision,
        string $logicalFileRef,
        string $role,
        ActorContext $actor,
    ): ContentItem;

    public function detach(
        ContentItemRef $itemRef,
        int $expectedRevision,
        string $logicalFileRef,
        string $role,
        ActorContext $actor,
    ): ContentItem;

    /**
     * The complete ordered manifest must contain no more than
     * ContentRevision::MAX_ATTACHMENTS placements.
     *
     * @param list<ContentAttachmentPlacement> $placements
     */
    public function reorder(
        ContentItemRef $itemRef,
        int $expectedRevision,
        array $placements,
        ActorContext $actor,
    ): ContentItem;

    public function publish(
        ContentItemRef $itemRef,
        int $expectedRevision,
        ActorContext $actor,
    ): ContentItem;

    public function unpublish(
        ContentItemRef $itemRef,
        int $expectedRevision,
        ActorContext $actor,
    ): ContentItem;
}
