<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

final class ContentPlatformMySqlTestSupport
{
    public static function create(): ContentRuntimeMySqlTestSupport
    {
        return ContentRuntimeMySqlTestSupport::create();
    }
}
