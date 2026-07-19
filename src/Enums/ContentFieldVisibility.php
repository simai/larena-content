<?php

declare(strict_types=1);

namespace Larena\Content\Enums;

enum ContentFieldVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case AdminOnly = 'admin_only';
}
