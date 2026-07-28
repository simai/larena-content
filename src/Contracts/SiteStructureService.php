<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;
use Larena\Content\ValueObjects\SiteStructureRevision;

interface SiteStructureService
{
    public function read(ActorContext $actor): SiteStructureRevision;

    public function revision(int $revision, ActorContext $actor): SiteStructureRevision;

    /** @return list<SiteStructureRevision> */
    public function revisions(ActorContext $actor): array;

    /** @return list<array<string, mixed>> */
    public function redirects(ActorContext $actor): array;

    /**
     * @param list<SiteStructureNode> $nodes
     * @param list<SiteSeoMetadata> $seo
     */
    public function replace(int $expectedRevision, array $nodes, array $seo, ActorContext $actor): SiteStructureRevision;

    public function submitForReview(int $expectedRevision, ActorContext $actor): SiteStructureRevision;

    public function publish(int $expectedRevision, ActorContext $actor): SiteStructureRevision;

    public function restore(int $restoreRevision, int $expectedRevision, ActorContext $actor): SiteStructureRevision;

    /** @return array<string, mixed> */
    public function published(): array;
}
