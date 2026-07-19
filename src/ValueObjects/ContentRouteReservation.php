<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentRouteReservation
{
    public function __construct(
        public ContentTypeKey $typeKey,
        public ContentLocale $locale,
        public ContentItemRef $itemRef,
        public ContentSlug $slug,
        public ?int $currentRevision,
        public ?int $publishedRevision,
    ) {
        if ($currentRevision === null && $publishedRevision === null) {
            throw new \InvalidArgumentException('A Content route must reserve a current or published revision.');
        }

        if (
            ($currentRevision !== null && $currentRevision < 1)
            || ($publishedRevision !== null && $publishedRevision < 1)
        ) {
            throw new \InvalidArgumentException('Content route revision pointers must be positive.');
        }
    }

    public function identity(): string
    {
        return implode(':', [$this->typeKey->value, $this->locale->value, $this->slug->value]);
    }
}
