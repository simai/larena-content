<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Support;

use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\ValueObjects\ContentItem;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;

/**
 * One shared fixture corpus used by feature, persistence and restart tests.
 */
final readonly class ContentPlatformScenario
{
    public function __construct(public ContentRuntimeHarness $runtime)
    {
    }

    public function createArticleType(): void
    {
        $fields = ContentPlatformV1Fixture::articleFields();
        $this->runtime->types->create(
            new ContentTypeKey('article'),
            $fields,
            ContentPlatformV1Fixture::articleProjectionContract(),
            ['label' => 'Article'],
            $this->runtime->actor(correlationId: 'scenario-type-article'),
        );
    }

    public function createEventType(): void
    {
        $fields = ContentPlatformV1Fixture::eventFields();
        $this->runtime->types->create(
            new ContentTypeKey('event'),
            $fields,
            ContentPlatformV1Fixture::eventProjectionContract(),
            ['label' => 'Event'],
            $this->runtime->actor(correlationId: 'scenario-type-event'),
        );
    }

    public function createBothTypes(): void
    {
        $this->createArticleType();
        $this->createEventType();
    }

    /**
     * @param array<string, string|int|bool|null> $overrides
     */
    public function createArticle(
        string $slug = 'first-article',
        ContentVisibility $visibility = ContentVisibility::Public,
        array $overrides = [],
    ): ContentItem {
        return $this->runtime->items->create(
            new ContentTypeKey('article'),
            new ContentLocale('en'),
            new ContentSlug($slug),
            $visibility,
            array_replace([
                'title' => 'First article',
                'body' => 'A deterministic public summary.',
                'featured' => true,
                'internal_notes' => 'never public',
            ], $overrides),
            $this->runtime->actor(correlationId: 'scenario-item-'.$slug),
        );
    }

    /**
     * @param array<string, string|int|bool|null> $overrides
     */
    public function createEvent(
        string $slug = 'launch-event',
        array $overrides = [],
    ): ContentItem {
        return $this->runtime->items->create(
            new ContentTypeKey('event'),
            new ContentLocale('en'),
            new ContentSlug($slug),
            ContentVisibility::Public,
            array_replace([
                'name' => 'Launch event',
                'starts_at_epoch' => 1_787_644_800,
                'registration_open' => true,
                'organizer_notes' => 'private runbook',
            ], $overrides),
            $this->runtime->actor(correlationId: 'scenario-event-'.$slug),
        );
    }

    public function insertAttachmentCorpus(): void
    {
        $this->runtime->insertFile(ContentRuntimeHarness::PUBLIC_FILE);
        $this->runtime->insertFile(ContentRuntimeHarness::SECOND_PUBLIC_FILE, [
            'display_name' => 'Second fixture',
            'public_id' => '028f62c6-9d27-7d19-b9b1-7cddfbd9a3e5',
            'alt_text' => '',
        ]);
        $this->runtime->insertFile(ContentRuntimeHarness::PRIVATE_FILE, [
            'display_name' => 'Private fixture',
            'public_id' => '028f62c6-9d27-7d19-b9b1-7cddfbd9a3e7',
            'visibility' => 'private',
        ]);
    }
}
