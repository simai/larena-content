<?php

declare(strict_types=1);

namespace Larena\Content\Enums;

enum ContentVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
