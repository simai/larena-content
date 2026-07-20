<?php

declare(strict_types=1);

namespace Larena\Content\Search;

use Illuminate\Contracts\Container\Container;
use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\ReindexSourceFactory;

/**
 * Lightweight singleton-safe factory. The request/connection-bound provider
 * is resolved only when Search processes a reindex batch.
 */
final readonly class ContainerContentSearchSourceFactory implements ReindexSourceFactory
{
    public function __construct(private Container $container)
    {
    }

    public function providerId(): string
    {
        return ContentSearchContract::PROVIDER_ID;
    }

    public function create(): ReindexSource
    {
        return $this->container->make(DatabaseContentSearchSourceProvider::class);
    }
}
