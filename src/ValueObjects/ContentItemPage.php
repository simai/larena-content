<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentItemPage
{
    /** @var list<ContentItem> */
    public array $items;

    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(
        array $items,
        public ?ContentItemRef $nextItemRef = null,
    ) {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Content item pages must contain an ordered list.');
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            if (!$item instanceof ContentItem) {
                throw new \InvalidArgumentException('Content item pages may contain only ContentItem objects.');
            }

            $normalizedItems[] = $item;
        }

        $this->items = $normalizedItems;
    }
}
