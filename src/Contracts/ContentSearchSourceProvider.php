<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ContentSearchProjection;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\SourceProvider;

/**
 * Rebuildable Search source over the exact public Content projection.
 */
interface ContentSearchSourceProvider extends ReindexSource
{
    public function descriptor(): SourceProvider;

    public function project(PublishedContentProjection $projection): ContentSearchProjection;
}
