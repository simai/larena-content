<?php

declare(strict_types=1);

namespace Larena\Content\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
