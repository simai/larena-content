<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\PublishedContentProjection;

/**
 * Sessionless product-adapter lookup of the exact published projection by
 * canonical Content item identity.
 */
interface PublishedContentItemReader
{
    public function readItem(ContentItemRef $itemRef): PublishedContentProjection;
}
