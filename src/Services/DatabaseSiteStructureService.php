<?php

declare(strict_types=1);

namespace Larena\Content\Services;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Content\Access\ContentAuthorizer;
use Larena\Content\Audit\ContentAuditEmitter;
use Larena\Content\Audit\ContentAuditPayload;
use Larena\Content\Contracts\PublishedContentItemReader;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Persistence\DatabaseSiteStructureRepository;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;
use Larena\Content\ValueObjects\SiteStructureRevision;
use Throwable;

final readonly class DatabaseSiteStructureService implements SiteStructureService
{
    public function __construct(
        private DatabaseSiteStructureRepository $repository,
        private ContentAuthorizer $authorizer,
        private PublishedContentItemReader $publishedContent,
        private ContentCanonicalJson $json,
        private ContentAuditEmitter $audit,
        private \Larena\Content\Contracts\ContentClock $clock,
    ) {
    }

    public function read(ActorContext $actor): SiteStructureRevision
    {
        $this->preflight($actor, 'content.structure.read');
        $head = $this->repository->head();
        if ($head === null) {
            throw new ContentRejected('site_structure_not_found');
        }

        return $this->hydrateRevision((int) $head['current_revision'], (int) ($head['published_revision'] ?? 0) ?: null);
    }

    public function revision(int $revision, ActorContext $actor): SiteStructureRevision
    {
        $this->preflight($actor, 'content.structure.read');
        if ($revision < 1) {
            throw new ContentRejected('site_structure_revision_invalid');
        }
        $head = $this->repository->head();
        if ($head === null) {
            throw new ContentRejected('site_structure_not_found');
        }

        return $this->hydrateRevision($revision, (int) ($head['published_revision'] ?? 0) ?: null);
    }

    public function revisions(ActorContext $actor): array
    {
        $this->preflight($actor, 'content.structure.read');
        $head = $this->repository->head();
        if ($head === null) {
            return [];
        }
        $published = (int) ($head['published_revision'] ?? 0) ?: null;

        return array_map(
            fn (array $row): SiteStructureRevision => $this->hydrateRevision((int) $row['revision'], $published),
            $this->repository->revisions(),
        );
    }

    public function redirects(ActorContext $actor): array
    {
        $this->preflight($actor, 'content.redirect.list');

        return $this->repository->redirects();
    }

    public function replace(int $expectedRevision, array $nodes, array $seo, ActorContext $actor): SiteStructureRevision
    {
        $connection = $this->preflight($actor, 'content.structure.update');

        try {
            [$nodes, $seo] = $this->normalize($nodes, $seo);
            return $connection->transaction(function () use ($expectedRevision, $nodes, $seo, $actor): SiteStructureRevision {
                $head = $this->repository->head(true);
                $now = $this->clock->now();
                if ($head === null) {
                    if ($expectedRevision !== 0) {
                        throw new ContentConflict($expectedRevision, 0);
                    }
                    $revision = $this->newRevision(1, 'draft', $nodes, $seo, null, $actor, $now);
                    $this->repository->create(
                        [
                            'structure_ref' => 'primary',
                            'current_revision' => 1,
                            'current_status' => 'draft',
                            'published_revision' => null,
                            'created_at' => $this->timestamp($now),
                            'updated_at' => $this->timestamp($now),
                        ],
                        $this->revisionRow($revision),
                    );
                } else {
                    $current = (int) $head['current_revision'];
                    if ($current !== $expectedRevision) {
                        throw new ContentConflict($expectedRevision, $current);
                    }
                    $revision = $this->newRevision(
                        $current + 1,
                        'draft',
                        $nodes,
                        $seo,
                        (int) ($head['published_revision'] ?? 0) ?: null,
                        $actor,
                        $now,
                    );
                    if (!$this->repository->append($current, $this->revisionRow($revision), 'draft', $revision->publishedRevision, $this->timestamp($now))) {
                        throw new ContentConflict($expectedRevision, $expectedRevision + 1);
                    }
                }
                $this->auditStructure('content.structure.updated', 'content.structure.update', $actor, $revision);

                return $revision;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDenial($actor, 'content.structure.update', $exception, $expectedRevision);
            throw $exception;
        }
    }

    public function submitForReview(int $expectedRevision, ActorContext $actor): SiteStructureRevision
    {
        return $this->transition($expectedRevision, 'review', 'content.structure.submit_review', 'content.structure.submitted_for_review', $actor, false);
    }

    public function publish(int $expectedRevision, ActorContext $actor): SiteStructureRevision
    {
        return $this->transition($expectedRevision, 'published', 'content.structure.publish', 'content.structure.published', $actor, true);
    }

    public function restore(int $restoreRevision, int $expectedRevision, ActorContext $actor): SiteStructureRevision
    {
        $connection = $this->preflight($actor, 'content.structure.restore');
        try {
            return $connection->transaction(function () use ($restoreRevision, $expectedRevision, $actor): SiteStructureRevision {
                $head = $this->lockedHead($expectedRevision);
                $target = $this->hydrateRevision($restoreRevision, (int) ($head['published_revision'] ?? 0) ?: null);
                $now = $this->clock->now();
                $revision = $this->newRevision(
                    $expectedRevision + 1,
                    'draft',
                    $target->nodes,
                    $target->seo,
                    (int) ($head['published_revision'] ?? 0) ?: null,
                    $actor,
                    $now,
                );
                if (!$this->repository->append($expectedRevision, $this->revisionRow($revision), 'draft', $revision->publishedRevision, $this->timestamp($now))) {
                    throw new ContentConflict($expectedRevision, $expectedRevision + 1);
                }
                $this->auditStructure('content.structure.restored', 'content.structure.restore', $actor, $revision);

                return $revision;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDenial($actor, 'content.structure.restore', $exception, $expectedRevision);
            throw $exception;
        }
    }

    public function published(): array
    {
        $this->repository->assertCompleteCompatible();
        $head = $this->repository->head();
        if ($head === null || $head['published_revision'] === null) {
            throw new ContentNotPublic();
        }
        try {
            $revision = $this->hydrateRevision((int) $head['published_revision'], (int) $head['published_revision']);
        } catch (ContentRejected) {
            throw new ContentNotPublic();
        }
        $projections = [];
        $nodes = $this->publicNodes($revision->nodes, $projections);

        $seo = [];
        foreach ($revision->seo as $metadata) {
            try {
                $projection = $projections[$metadata->itemRef->value] ??= $this->publishedContent->readItem($metadata->itemRef);
            } catch (ContentNotPublic) {
                continue;
            }
            $seo[$metadata->itemRef->value] = [
                ...$metadata->toArray(),
                'canonical_path' => $metadata->canonicalPath ?? $this->locator($projection),
            ];
        }
        ksort($seo, SORT_STRING);

        return [
            'structure_ref' => 'primary',
            'revision' => $revision->revision,
            'nodes' => $nodes,
            'seo' => $seo,
        ];
    }

    private function transition(int $expectedRevision, string $status, string $operation, string $event, ActorContext $actor, bool $publish): SiteStructureRevision
    {
        $connection = $this->preflight($actor, $operation);
        try {
            return $connection->transaction(function () use ($expectedRevision, $status, $operation, $event, $actor, $publish): SiteStructureRevision {
                $head = $this->lockedHead($expectedRevision);
                $current = $this->hydrateRevision($expectedRevision, (int) ($head['published_revision'] ?? 0) ?: null);
                if ($publish) {
                    $this->assertPublishable($current);
                }
                $now = $this->clock->now();
                $next = $expectedRevision + 1;
                $publishedRevision = $publish ? $next : ((int) ($head['published_revision'] ?? 0) ?: null);
                $revision = $this->newRevision($next, $status, $current->nodes, $current->seo, $publishedRevision, $actor, $now);
                if (!$this->repository->append($expectedRevision, $this->revisionRow($revision), $status, $publishedRevision, $this->timestamp($now))) {
                    throw new ContentConflict($expectedRevision, $next);
                }
                $this->auditStructure($event, $operation, $actor, $revision);

                return $revision;
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDenial($actor, $operation, $exception, $expectedRevision);
            throw $exception;
        }
    }

    /**
     * @param list<SiteStructureNode> $nodes
     * @param list<SiteSeoMetadata> $seo
     * @return array{list<SiteStructureNode>, list<SiteSeoMetadata>}
     */
    private function normalize(array $nodes, array $seo): array
    {
        if (count($nodes) > 200 || count($seo) > 500) {
            throw new ContentRejected('site_structure_limits_exceeded');
        }
        usort($nodes, static fn (SiteStructureNode $a, SiteStructureNode $b): int => [$a->parentRef ?? '', $a->position, $a->nodeRef] <=> [$b->parentRef ?? '', $b->position, $b->nodeRef]);
        usort($seo, static fn (SiteSeoMetadata $a, SiteSeoMetadata $b): int => $a->itemRef->value <=> $b->itemRef->value);
        $byRef = [];
        $positions = [];
        foreach ($nodes as $node) {
            if (isset($byRef[$node->nodeRef])) {
                throw new ContentRejected('site_structure_node_duplicate');
            }
            $byRef[$node->nodeRef] = $node;
            $positions[$node->parentRef ?? ''][] = $node->position;
        }
        foreach ($nodes as $node) {
            if ($node->parentRef !== null && !isset($byRef[$node->parentRef])) {
                throw new ContentRejected('site_structure_parent_missing');
            }
            $seen = [$node->nodeRef => true];
            $parent = $node->parentRef;
            $depth = 0;
            while ($parent !== null) {
                if (isset($seen[$parent]) || ++$depth > 8) {
                    throw new ContentRejected('site_structure_cycle_or_depth');
                }
                $seen[$parent] = true;
                $parent = $byRef[$parent]->parentRef;
            }
        }
        foreach ($positions as $siblings) {
            sort($siblings, SORT_NUMERIC);
            if ($siblings !== range(0, count($siblings) - 1)) {
                throw new ContentRejected('site_structure_positions_invalid');
            }
        }
        $seoItems = [];
        $canonicals = [];
        foreach ($seo as $entry) {
            if (isset($seoItems[$entry->itemRef->value])) {
                throw new ContentRejected('site_structure_seo_duplicate');
            }
            $seoItems[$entry->itemRef->value] = true;
            if ($entry->canonicalPath !== null && isset($canonicals[$entry->canonicalPath])) {
                throw new ContentRejected('canonical_conflict');
            }
            if ($entry->canonicalPath !== null) {
                $canonicals[$entry->canonicalPath] = true;
            }
        }
        $this->json->assertMaximumBytes(array_map(static fn (SiteStructureNode $node): array => $node->toArray(), $nodes), 262144, 'site_structure_nodes_too_large');
        $this->json->assertMaximumBytes(array_map(static fn (SiteSeoMetadata $entry): array => $entry->toArray(), $seo), 262144, 'site_structure_seo_too_large');

        return [$nodes, $seo];
    }

    private function assertPublishable(SiteStructureRevision $revision): void
    {
        $paths = [];
        foreach ($revision->nodes as $node) {
            if ($node->targetType === 'content') {
                $itemRef = $node->contentItemRef;
                if (!$itemRef instanceof ContentItemRef) {
                    throw new ContentRejected('site_structure_target_invalid');
                }
                try {
                    $projection = $this->publishedContent->readItem($itemRef);
                } catch (ContentNotPublic $exception) {
                    throw new ContentRejected('site_structure_target_not_published', previous: $exception);
                }
                $path = $this->locator($projection);
                if (isset($paths[$path]) && $paths[$path] !== $itemRef->value) {
                    throw new ContentRejected('content_route_conflict');
                }
                $paths[$path] = $itemRef->value;
            }
        }
        $claimed = [];
        foreach ($revision->seo as $metadata) {
            try {
                $projection = $this->publishedContent->readItem($metadata->itemRef);
            } catch (ContentNotPublic $exception) {
                throw new ContentRejected('site_structure_seo_target_not_published', previous: $exception);
            }
            $path = $metadata->canonicalPath ?? $this->locator($projection);
            if (isset($paths[$path]) && $paths[$path] !== $metadata->itemRef->value) {
                throw new ContentRejected('canonical_conflict');
            }
            if (isset($claimed[$path]) && $claimed[$path] !== $metadata->itemRef->value) {
                throw new ContentRejected('canonical_conflict');
            }
            $claimed[$path] = $metadata->itemRef->value;
        }
    }

    /**
     * @param list<SiteStructureNode> $source
     * @param array<string, \Larena\Content\ValueObjects\PublishedContentProjection> $projections
     * @return list<array{node_ref:string,parent_ref:?string,position:int,label:string,target:array{type:string,url:string,item_ref?:string}}>
     */
    private function publicNodes(array $source, array &$projections): array
    {
        $children = [];
        foreach ($source as $node) {
            $children[$node->parentRef ?? ''][] = $node;
        }
        foreach ($children as &$siblings) {
            usort($siblings, static fn (SiteStructureNode $a, SiteStructureNode $b): int => [$a->position, $a->nodeRef] <=> [$b->position, $b->nodeRef]);
        }
        unset($siblings);

        $result = [];
        $append = function (?string $parentRef) use (&$append, &$children, &$projections, &$result): void {
            foreach ($children[$parentRef ?? ''] ?? [] as $node) {
                if (!$node->visible) {
                    continue;
                }
                $target = $this->publicTarget($node, $projections);
                if ($target === null) {
                    continue;
                }
                $result[] = [
                    'node_ref' => $node->nodeRef,
                    'parent_ref' => $node->parentRef,
                    'position' => $node->position,
                    'label' => $node->label,
                    'target' => $target,
                ];
                $append($node->nodeRef);
            }
        };
        $append(null);

        return $result;
    }

    /**
     * @param array<string, \Larena\Content\ValueObjects\PublishedContentProjection> $projections
     * @return array{type:string,url:string,item_ref?:string}|null
     */
    private function publicTarget(SiteStructureNode $node, array &$projections): ?array
    {
        if ($node->targetType === 'external') {
            return ['type' => 'external', 'url' => (string) $node->externalUrl];
        }
        $itemRef = $node->contentItemRef;
        if (!$itemRef instanceof ContentItemRef) {
            return null;
        }
        try {
            $projection = $projections[$itemRef->value] ??= $this->publishedContent->readItem($itemRef);
        } catch (ContentNotPublic) {
            return null;
        }

        return ['type' => 'content', 'item_ref' => $itemRef->value, 'url' => $this->locator($projection)];
    }

    private function locator(\Larena\Content\ValueObjects\PublishedContentProjection $projection): string
    {
        $path = '/content/'.$projection->typeKey->value.'/'.$projection->slug->value;

        return $projection->locale->value === 'en' ? $path : $path.'?locale='.rawurlencode($projection->locale->value);
    }

    /** @return array<string, mixed> */
    private function lockedHead(int $expectedRevision): array
    {
        $head = $this->repository->head(true);
        $current = $head === null ? 0 : (int) $head['current_revision'];
        if ($head === null || $current !== $expectedRevision) {
            throw new ContentConflict($expectedRevision, $current);
        }

        return $head;
    }

    /**
     * @param list<SiteStructureNode> $nodes
     * @param list<SiteSeoMetadata> $seo
     */
    private function newRevision(int $revision, string $status, array $nodes, array $seo, ?int $publishedRevision, ActorContext $actor, DateTimeImmutable $now): SiteStructureRevision
    {
        return new SiteStructureRevision('primary', $revision, $status, $nodes, $seo, $publishedRevision, $actor->actorRef, $actor->correlationId, $now);
    }

    private function hydrateRevision(int $revision, ?int $publishedRevision): SiteStructureRevision
    {
        $row = $this->repository->revision($revision);
        if ($row === null) {
            throw new ContentRejected('site_structure_revision_not_found');
        }
        try {
            $nodes = json_decode((string) $row['nodes_json'], true, 64, JSON_THROW_ON_ERROR);
            $seo = json_decode((string) $row['seo_json'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($nodes) || !array_is_list($nodes) || !is_array($seo) || !array_is_list($seo)) {
                throw new \InvalidArgumentException('Persisted site-structure JSON is invalid.');
            }
            [$nodes, $seo] = $this->normalize(
                array_map(static fn (array $node): SiteStructureNode => SiteStructureNode::fromArray($node), $nodes),
                array_map(static fn (array $entry): SiteSeoMetadata => SiteSeoMetadata::fromArray($entry), $seo),
            );

            return new SiteStructureRevision(
                'primary',
                (int) $row['revision'],
                (string) $row['status'],
                $nodes,
                $seo,
                $publishedRevision,
                (string) $row['created_by'],
                (string) $row['correlation_id'],
                new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            );
        } catch (ContentRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentRejected('site_structure_persisted_invalid', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function revisionRow(SiteStructureRevision $revision): array
    {
        return [
            'structure_ref' => 'primary',
            'revision' => $revision->revision,
            'status' => $revision->status,
            'nodes_json' => $this->json->encode(array_map(static fn (SiteStructureNode $node): array => $node->toArray(), $revision->nodes)),
            'seo_json' => $this->json->encode(array_map(static fn (SiteSeoMetadata $entry): array => $entry->toArray(), $revision->seo)),
            'created_by' => $revision->createdBy,
            'correlation_id' => $revision->correlationId,
            'created_at' => $this->timestamp($revision->createdAt),
        ];
    }

    private function auditStructure(string $event, string $operation, ActorContext $actor, SiteStructureRevision $revision): void
    {
        $this->audit->emit($event, $actor, 'content:structure:primary', ContentAuditPayload::from([
            'operation' => $operation,
            'structure_ref' => 'primary',
            'new_revision' => $revision->revision,
            'status' => $revision->status,
            'node_count' => count($revision->nodes),
            'seo_count' => count($revision->seo),
        ]));
    }

    private function auditDenial(ActorContext $actor, string $operation, ContentRejected $exception, int $expectedRevision): void
    {
        $this->repository->connection()->transaction(function () use ($actor, $operation, $exception, $expectedRevision): void {
            $this->audit->domainDenied($actor, $operation, $exception->reasonCode(), 'content:structure:primary', [
                'structure_ref' => 'primary',
                'expected_revision' => max(0, $expectedRevision),
            ]);
        }, 1);
    }

    private function preflight(ActorContext $actor, string $operation): \Illuminate\Database\Connection
    {
        $this->authorizer->assertAllowed($actor, $operation);
        $this->repository->assertCompleteCompatible();

        return $this->repository->connection();
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
