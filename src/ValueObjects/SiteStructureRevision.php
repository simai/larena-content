<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use DateTimeImmutable;

final readonly class SiteStructureRevision
{
    /**
     * @param list<SiteStructureNode> $nodes
     * @param list<SiteSeoMetadata> $seo
     */
    public function __construct(
        public string $structureRef,
        public int $revision,
        public string $status,
        public array $nodes,
        public array $seo,
        public ?int $publishedRevision,
        public string $createdBy,
        public string $correlationId,
        public DateTimeImmutable $createdAt,
    ) {
        if ($structureRef !== 'primary' || $revision < 1 || ($publishedRevision !== null && $publishedRevision < 1)) {
            throw new \InvalidArgumentException('Invalid site-structure revision identity.');
        }
        if (!in_array($status, ['draft', 'review', 'published'], true)) {
            throw new \InvalidArgumentException('Invalid site-structure revision status.');
        }
        if (count($nodes) > 200 || count($seo) > 500) {
            throw new \InvalidArgumentException('Site-structure revision limits exceeded.');
        }
    }
}
