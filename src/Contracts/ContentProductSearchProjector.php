<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Search\Contracts\SearchWriteResult;

/**
 * Product-owned Search adapter invoked inside the Content mutation
 * transaction for an explicitly delegated Content type.
 */
interface ContentProductSearchProjector
{
    public function providerId(): string;

    public function sourceRef(ContentItemRef $itemRef): string;

    public function upsert(PublishedContentProjection $projection): SearchWriteResult;

    public function remove(ContentItemRef $itemRef, int $sourceRevision): SearchWriteResult;
}
