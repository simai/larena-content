<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentAttachmentPage
{
    /** @var list<ContentAttachmentReference> */
    public array $items;

    /** @param array<array-key, mixed> $items */
    public function __construct(
        public ContentItemRef $itemRef,
        public int $revision,
        array $items,
    ) {
        if ($revision < 1 || count($items) > ContentRevision::MAX_ATTACHMENTS) {
            throw new \InvalidArgumentException('Content attachment page bounds are invalid.');
        }
        $normalized = [];
        foreach ($items as $item) {
            if (
                !$item instanceof ContentAttachmentReference
                || $item->itemRef->value !== $itemRef->value
                || $item->revision !== $revision
            ) {
                throw new \InvalidArgumentException(
                    'A Content attachment page must contain one exact revision manifest.',
                );
            }
            $normalized[] = $item;
        }
        $this->items = $normalized;
    }
}
