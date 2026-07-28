<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;

interface ManagedContentRedirectReader
{
    /** @return array{type_key:string,locale:string,slug:string,status:int}|null */
    public function resolve(ContentTypeKey $typeKey, ContentSlug $slug, ContentLocale $locale): ?array;
}
