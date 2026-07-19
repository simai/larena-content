<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentRevisionPage
{
    /** @var list<ContentRevision> */
    public array $items;

    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(
        public ContentItemRef $itemRef,
        array $items,
        public ?int $nextRevision = null,
    ) {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Content revision pages must contain an ordered list.');
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            if (!$item instanceof ContentRevision || $item->itemRef->value !== $itemRef->value) {
                throw new \InvalidArgumentException('A Content revision page may contain revisions for one item only.');
            }

            $normalizedItems[] = $item;
        }

        if ($nextRevision !== null && $nextRevision < 1) {
            throw new \InvalidArgumentException('The next Content revision cursor must be positive.');
        }

        $this->items = $normalizedItems;
    }
}
