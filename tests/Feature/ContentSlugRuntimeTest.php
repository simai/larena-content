<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Enums\ContentVisibility;
use Larena\Content\Exceptions\ContentNotPublic;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;

final class ContentSlugRuntimeTest extends TestCase
{
    private ContentRuntimeHarness $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = ContentRuntimeHarness::create();
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        parent::tearDown();
    }

    public function test_slug_is_type_and_locale_scoped_and_old_public_route_is_removed_after_republish(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createBothTypes();
        $article = $scenario->createArticle('shared-slug');
        $scenario->createEvent('shared-slug');

        $this->runtime->items->create(
            new ContentTypeKey('article'),
            new ContentLocale('fr'),
            new ContentSlug('shared-slug'),
            ContentVisibility::Public,
            [
                'title' => 'French article',
                'body' => 'French summary',
                'featured' => false,
                'internal_notes' => null,
            ],
            $this->runtime->actor(),
        );

        try {
            $scenario->createArticle('shared-slug');
            self::fail('A duplicate type-locale slug was accepted.');
        } catch (ContentRejected $exception) {
            self::assertSame('slug_conflict', $exception->reasonCode());
        }

        $article = $this->runtime->items->publish(
            $article->itemRef,
            1,
            $this->runtime->actor(),
        );
        $draft = $this->runtime->items->update(
            $article->itemRef,
            2,
            new ContentSlug('new-public-slug'),
            ContentVisibility::Public,
            [
                'title' => 'Renamed public article',
                'body' => 'New body',
                'featured' => true,
                'internal_notes' => 'private',
            ],
            $this->runtime->actor(),
        );

        self::assertSame(
            'First article',
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('shared-slug'),
                new ContentLocale('en'),
            )->publicFields['title'],
        );
        $this->runtime->items->publish(
            $draft->itemRef,
            3,
            $this->runtime->actor(),
        );

        try {
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('shared-slug'),
                new ContentLocale('en'),
            );
            self::fail('The stale published route remained public after republish.');
        } catch (ContentNotPublic $exception) {
            self::assertSame('content_not_public', $exception->reasonCode());
        }
        self::assertSame(
            'Renamed public article',
            $this->runtime->published->read(
                new ContentTypeKey('article'),
                new ContentSlug('new-public-slug'),
                new ContentLocale('en'),
            )->publicFields['title'],
        );
    }
}
