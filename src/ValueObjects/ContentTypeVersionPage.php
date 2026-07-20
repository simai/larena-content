<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentTypeVersionPage
{
    /** @var list<ContentTypeVersion> */
    public array $items;

    /**
     * @param array<array-key, mixed> $items
     */
    public function __construct(
        public ContentTypeKey $typeKey,
        array $items,
        public ?int $nextAfterVersion,
    ) {
        $normalized = [];
        foreach ($items as $item) {
            if (
                !$item instanceof ContentTypeVersion
                || $item->typeKey->value !== $typeKey->value
            ) {
                throw new \InvalidArgumentException(
                    'A Content type-version page must contain versions of exactly one type.',
                );
            }
            $normalized[] = $item;
        }

        $this->items = $normalized;
    }
}
