<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use DateTimeImmutable;

interface ContentClock
{
    public function now(): DateTimeImmutable;
}
