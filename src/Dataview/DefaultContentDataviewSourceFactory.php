<?php

declare(strict_types=1);

namespace Larena\Content\Dataview;

use DateTimeZone;
use Larena\Content\Contracts\ContentDataviewSourceFactory;
use Larena\Content\Contracts\ContentDataviewSourceProvider;
use Larena\Content\Contracts\ContentItemService;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentItemQuery;

final readonly class DefaultContentDataviewSourceFactory implements ContentDataviewSourceFactory
{
    public function __construct(private ContentItemService $items)
    {
    }

    public function forItems(
        ContentItemQuery $query,
        ActorContext $actor,
    ): ContentDataviewSourceProvider {
        $page = $this->items->list($query, $actor);

        return new MaterializedContentDataviewSourceProvider(array_map(
            static fn (ContentItem $item): array => [
                'item_ref' => $item->itemRef->value,
                'type_key' => $item->typeKey->value,
                'locale' => $item->locale->value,
                'current_revision' => $item->currentRevision,
                'current_slug' => $item->currentSlug->value,
                'current_status' => $item->currentStatus->value,
                'current_visibility' => $item->currentVisibility->value,
                'published_revision' => $item->publishedRevision,
                'published_slug' => $item->publishedSlug?->value,
                'published_at' => $item->publishedAt?->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.u\Z'),
            ],
            $page->items,
        ));
    }
}
