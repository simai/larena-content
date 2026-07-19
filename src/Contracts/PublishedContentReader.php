<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\PublishedContentProjection;

/**
 * Sessionless, read-only public projection boundary.
 *
 * Implementations fail closed with ContentNotPublic rather than disclosing
 * whether a protected type, item, revision, route, or file exists.
 */
interface PublishedContentReader
{
    public function read(
        ContentTypeKey $typeKey,
        ContentSlug $slug,
        ContentLocale $locale,
    ): PublishedContentProjection;
}
