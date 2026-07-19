<?php

declare(strict_types=1);

namespace Larena\Content\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Content\Contracts\ContentClock;

final class SystemContentClock implements ContentClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
