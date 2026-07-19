<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentSearchProjection;

final class ContentContractFixtureCoverageTest extends TestCase
{
    private const FEATURES = [
        'content.type_registry',
        'content.item_lifecycle',
        'content.revision_history',
        'content.slug_routing',
        'content.attachment_binding',
        'content.publication_projection',
        'content.search_projection',
    ];

    public function test_two_arbitrary_type_shapes_use_the_same_contracts(): void
    {
        $articleFields = ContentPlatformV1Fixture::articleFields();
        $eventFields = ContentPlatformV1Fixture::eventFields();

        self::assertSame(['title', 'body', 'featured', 'internal_notes'], array_column($articleFields, 'key'));
        self::assertSame(['name', 'starts_at_epoch', 'registration_open', 'organizer_notes'], array_column($eventFields, 'key'));
        self::assertSame('title', ContentPlatformV1Fixture::articleProjectionContract()->titleField);
        self::assertSame('name', ContentPlatformV1Fixture::eventProjectionContract()->titleField);
    }

    public function test_fixture_matrix_has_positive_and_negative_proof_for_every_feature(): void
    {
        $coverage = ContentPlatformV1Fixture::featureCoverage();

        self::assertSame(self::FEATURES, array_keys($coverage));
        foreach ($coverage as $feature => $scenarios) {
            self::assertNotSame([], $scenarios['positive'], $feature.' requires positive coverage.');
            self::assertNotSame([], $scenarios['negative'], $feature.' requires negative coverage.');
        }
    }

    public function test_public_fixture_omits_private_and_admin_fields_from_public_and_search(): void
    {
        $published = ContentPlatformV1Fixture::publishedArticle();
        $public = $published->toArray();
        $search = ContentSearchProjection::fromPublished($published)->toArray();

        self::assertArrayNotHasKey('internal_notes', $public['public_fields']);
        self::assertArrayNotHasKey('organizer_notes', $public['public_fields']);
        self::assertSame(
            ['type_key', 'slug', 'locale', 'content_revision', 'projection_version'],
            array_keys($search['payload']),
        );
        self::assertStringNotContainsString('internal_notes', json_encode($search, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('logical_file_ref', json_encode($search, JSON_THROW_ON_ERROR));
    }

    public function test_guarded_runtime_batch_contains_no_admin_or_frontend_surface(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'routes/admin.php',
            'resources/views',
            'resources/js',
            'src/Http/Controllers/Admin',
        ] as $forbidden) {
            self::assertFileDoesNotExist($root.'/'.$forbidden);
        }
    }
}
