<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\CmsSitePackReport;

interface CmsSitePackService
{
    public function export(ActorContext $actor): CmsSitePackReport;

    public function verify(string $packageRef, ActorContext $actor): CmsSitePackReport;

    public function dryRun(string $packageRef, ActorContext $actor): CmsSitePackReport;

    public function import(string $packageRef, ActorContext $actor): CmsSitePackReport;
}
