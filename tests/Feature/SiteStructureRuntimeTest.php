<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;

final class SiteStructureRuntimeTest extends TestCase
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

    public function test_editor_prepares_reader_only_reads_and_administrator_publishes_and_restores(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('site-page');
        $item = $this->runtime->items->publish($item->itemRef, 1, $this->runtime->admin);

        $draft = $this->runtime->siteStructure->replace(0, [
            new SiteStructureNode(
                '128f62c6-9d27-4d19-89b1-7cddfbd9a301',
                null,
                0,
                'Page',
                true,
                'content',
                $item->itemRef,
            ),
            new SiteStructureNode(
                '128f62c6-9d27-4d19-89b1-7cddfbd9a302',
                null,
                1,
                'External',
                true,
                'external',
                null,
                'https://example.com/docs',
            ),
        ], [
            new SiteSeoMetadata($item->itemRef, '/knowledge/site-page', 'SEO page', 'Public description', 'index,follow'),
        ], $this->runtime->editor);

        self::assertSame(1, $draft->revision);
        self::assertSame('draft', $this->runtime->siteStructure->read($this->runtime->reader)->status);

        try {
            $this->runtime->siteStructure->replace(1, [], [], $this->runtime->reader);
            self::fail('Reader unexpectedly mutated the structure.');
        } catch (AccessMutationRejected $exception) {
            self::assertSame('access_actor_forbidden', $exception->reasonCode);
        }

        $review = $this->runtime->siteStructure->submitForReview(1, $this->runtime->editor);
        self::assertSame('review', $review->status);
        try {
            $this->runtime->siteStructure->publish(2, $this->runtime->editor);
            self::fail('Editor unexpectedly published the structure.');
        } catch (AccessMutationRejected $exception) {
            self::assertSame('access_actor_forbidden', $exception->reasonCode);
        }

        $published = $this->runtime->siteStructure->publish(2, $this->runtime->admin);
        self::assertSame(3, $published->publishedRevision);
        $public = $this->runtime->siteStructure->published();
        self::assertSame(3, $public['revision']);
        self::assertCount(2, $public['nodes']);
        self::assertSame('/knowledge/site-page', $public['seo'][$item->itemRef->value]['canonical_path']);

        $restored = $this->runtime->siteStructure->restore(1, 3, $this->runtime->admin);
        self::assertSame(4, $restored->revision);
        self::assertSame('draft', $restored->status);
        self::assertSame(3, $restored->publishedRevision);
        self::assertSame(3, $this->runtime->siteStructure->published()['revision']);
        self::assertSame(4, $this->runtime->contentAuditCount('content.structure.updated')
            + $this->runtime->contentAuditCount('content.structure.submitted_for_review')
            + $this->runtime->contentAuditCount('content.structure.published')
            + $this->runtime->contentAuditCount('content.structure.restored'));
    }

    public function test_invalid_tree_url_canonical_and_unpublished_target_fail_closed(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('draft-only');

        $this->runtime->siteStructure->replace(0, [
            new SiteStructureNode(
                '228f62c6-9d27-4d19-89b1-7cddfbd9a301',
                null,
                0,
                'Draft',
                true,
                'content',
                $item->itemRef,
            ),
        ], [], $this->runtime->editor);
        try {
            $this->runtime->siteStructure->publish(1, $this->runtime->admin);
            self::fail('A structure with an unpublished Content target was published.');
        } catch (ContentRejected $exception) {
            self::assertSame('site_structure_target_not_published', $exception->reasonCode());
        }
        self::assertNull($this->runtime->connection->table('larena_content_site_structures')->value('published_revision'));
    }

    public function test_structure_survives_fresh_process_restart(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $item = $scenario->createArticle('restart-page');
        $item = $this->runtime->items->publish($item->itemRef, 1, $this->runtime->admin);
        $this->runtime->siteStructure->replace(0, [
            new SiteStructureNode('328f62c6-9d27-4d19-89b1-7cddfbd9a301', null, 0, 'Restart', true, 'content', $item->itemRef),
        ], [new SiteSeoMetadata($item->itemRef, null, 'Restart SEO', null)], $this->runtime->editor);
        $this->runtime->siteStructure->publish(1, $this->runtime->admin);
        $path = $this->runtime->databasePath();
        $this->runtime->close(false);

        $reopened = ContentRuntimeHarness::reopen($path, true);
        $this->runtime = $reopened;
        self::assertSame(2, $reopened->siteStructure->published()['revision']);
        self::assertSame('/content/article/restart-page', $reopened->siteStructure->published()['seo'][$item->itemRef->value]['canonical_path']);
    }

    public function test_public_projection_is_depth_first_even_when_uuid_sort_order_is_adverse(): void
    {
        $this->runtime->siteStructure->replace(0, [
            new SiteStructureNode('ffffffff-ffff-4fff-8fff-fffffffffff1', null, 0, 'Root', true, 'external', null, 'https://example.com/root'),
            new SiteStructureNode('11111111-1111-4111-8111-111111111111', 'ffffffff-ffff-4fff-8fff-fffffffffff1', 0, 'Child', true, 'external', null, 'https://example.com/child'),
            new SiteStructureNode('22222222-2222-4222-8222-222222222222', '11111111-1111-4111-8111-111111111111', 0, 'Grandchild', true, 'external', null, 'https://example.com/grandchild'),
        ], [], $this->runtime->editor);
        $this->runtime->siteStructure->publish(1, $this->runtime->admin);

        self::assertSame(
            ['Root', 'Child', 'Grandchild'],
            array_column($this->runtime->siteStructure->published()['nodes'], 'label'),
        );
    }

    public function test_canonical_cannot_claim_another_navigation_target_route(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $first = $scenario->createArticle('first-route');
        $first = $this->runtime->items->publish($first->itemRef, 1, $this->runtime->admin);
        $second = $scenario->createArticle('second-route');
        $second = $this->runtime->items->publish($second->itemRef, 1, $this->runtime->admin);
        $this->runtime->siteStructure->replace(0, [
            new SiteStructureNode('428f62c6-9d27-4d19-89b1-7cddfbd9a301', null, 0, 'First', true, 'content', $first->itemRef),
        ], [
            new SiteSeoMetadata($second->itemRef, '/content/article/first-route', 'Second SEO', null),
        ], $this->runtime->editor);

        try {
            $this->runtime->siteStructure->publish(1, $this->runtime->admin);
            self::fail('A canonical URL claimed another published Content route.');
        } catch (ContentRejected $exception) {
            self::assertSame('canonical_conflict', $exception->reasonCode());
        }
    }
}
