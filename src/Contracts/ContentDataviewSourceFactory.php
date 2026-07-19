<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItemQuery;

interface ContentDataviewSourceFactory
{
    public function forItems(
        ContentItemQuery $query,
        ActorContext $actor,
    ): ContentDataviewSourceProvider;
}
