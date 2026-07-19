<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ContentItemRef;

interface ContentIdGenerator
{
    public function newItemRef(): ContentItemRef;
}
