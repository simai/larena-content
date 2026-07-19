<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeQuery;
use Larena\Content\Tests\TestCase;

final class ContentTypeRuntimeTest extends TestCase
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

    public function test_two_arbitrary_typed_schemas_are_owned_by_storage_and_read_by_content(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createBothTypes();

        $page = $this->runtime->types->list(
            new ContentTypeQuery(limit: 100),
            $this->runtime->actor(),
        );
        self::assertSame(
            ['article', 'event'],
            array_map(
                static fn ($type): string => $type->typeKey->value,
                $page->items,
            ),
        );

        $article = $this->runtime->types->version(
            new ContentTypeKey('article'),
            1,
            $this->runtime->actor(),
        );
        $event = $this->runtime->types->version(
            new ContentTypeKey('event'),
            1,
            $this->runtime->actor(),
        );

        self::assertSame(
            ['string', 'string', 'boolean', 'string'],
            array_map(
                static fn ($field): string => $field->propertyType,
                $article->fieldDefinitions,
            ),
        );
        self::assertSame(
            ['string', 'integer', 'boolean', 'string'],
            array_map(
                static fn ($field): string => $field->propertyType,
                $event->fieldDefinitions,
            ),
        );
        self::assertSame(2, $this->runtime->connection->table('larena_content_types')->count());
        self::assertSame(2, $this->runtime->connection->table('larena_storage_schemas')->count());
        self::assertSame(0, $this->runtime->connection->table('larena_storage_records')->count());
        self::assertSame(2, $this->runtime->contentAuditCount('content.type.created'));
    }
}
