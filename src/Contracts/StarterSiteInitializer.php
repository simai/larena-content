<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

interface StarterSiteInitializer
{
    public function firstRunState(): string;

    public function initialize(string $subjectRef, string $siteName, string $locale): string;
}
