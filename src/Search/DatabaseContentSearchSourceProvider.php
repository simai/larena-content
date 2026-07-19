<?php

declare(strict_types=1);

namespace Larena\Content\Search;

use Larena\Content\Contracts\ContentSearchSourceProvider;
use Larena\Content\Contracts\PublishedContentReader;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSearchProjection;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\PublishedContentProjection;
use Larena\Search\Contracts\ReindexBatch;
use Larena\Search\Contracts\SourceProvider;
use Throwable;

/**
 * Rebuilds Search exclusively from the same public projection boundary used
 * by the anonymous HTTP route.
 */
final readonly class DatabaseContentSearchSourceProvider implements ContentSearchSourceProvider
{
    public function __construct(
        private DatabaseContentRepository $content,
        private PublishedContentReader $published,
        private ContentParticipantGuard $participants,
    ) {
    }

    public function providerId(): string
    {
        return ContentSearchContract::PROVIDER_ID;
    }

    public function descriptor(): SourceProvider
    {
        return ContentSearchContract::descriptor();
    }

    public function project(PublishedContentProjection $projection): ContentSearchProjection
    {
        return ContentSearchProjection::fromPublished($projection);
    }

    public function readBatch(?string $afterCursor, int $limit): ReindexBatch
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('content_search_reindex_limit_invalid');
        }

        if ($afterCursor !== null) {
            new ContentItemRef($afterCursor);
        }

        try {
            $this->participants->assertSharedConnection();
            $this->content->assertCompleteCompatible();
            $rows = $this->content->publishedItemRows($afterCursor, $limit);
            $projections = [];

            foreach ($rows as $row) {
                $itemRef = new ContentItemRef((string) $row['item_ref']);
                $typeKey = new ContentTypeKey((string) $row['type_key']);
                $locale = new ContentLocale((string) $row['locale']);
                $slug = new ContentSlug((string) $row['published_slug']);

                try {
                    $projection = $this->published->read($typeKey, $slug, $locale);
                } catch (ContentNotPublic) {
                    // A concurrently removed or fail-closed projection is not
                    // eligible for Search and must disclose no replacement.
                    continue;
                }

                if (
                    $projection->itemRef->value !== $itemRef->value
                    || $projection->typeKey->value !== $typeKey->value
                    || $projection->locale->value !== $locale->value
                    || $projection->slug->value !== $slug->value
                    || $projection->publishedRevision !== (int) $row['published_revision']
                ) {
                    throw new ContentIntegrationFailed(
                        'search',
                        'published_projection_identity_mismatch',
                    );
                }

                $projections[] = $this->project($projection)->toSearchProjection();
            }

            $nextCursor = $rows === []
                ? $afterCursor
                : (string) $rows[array_key_last($rows)]['item_ref'];
            $hasMore = count($rows) === $limit
                && $this->content->publishedItemRows($nextCursor, 1) !== [];

            return new ReindexBatch($projections, $nextCursor, $hasMore);
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'search',
                'content_search_reindex_read_failed',
                $exception,
            );
        }
    }
}
