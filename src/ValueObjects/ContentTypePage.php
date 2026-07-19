<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentTypePage
{
    /** @var list<ContentType> */
    public array $items;

    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(
        array $items,
        public ?ContentTypeKey $nextTypeKey = null,
    ) {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Content type pages must contain an ordered list.');
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            if (!$item instanceof ContentType) {
                throw new \InvalidArgumentException('Content type pages may contain only ContentType objects.');
            }

            $normalizedItems[] = $item;
        }

        $this->items = $normalizedItems;
    }
}
