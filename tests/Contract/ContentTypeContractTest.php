<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use DateTimeImmutable;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentType;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypePage;
use Larena\Content\ValueObjects\ContentTypeQuery;
use Larena\Content\ValueObjects\ContentTypeVersion;
use PHPUnit\Framework\TestCase;

final class ContentTypeContractTest extends TestCase
{
    public function testTypeVersionReferencesExactStorageSchemaAndFrozenProjection(): void
    {
        $typeKey = new ContentTypeKey('article');
        $fields = $this->fields();
        $projection = new ContentProjectionContract(
            version: 1,
            titleField: 'title',
            snippetField: 'body',
            searchableFields: ['title', 'body', 'priority', 'featured'],
            fieldDefinitions: $fields,
        );
        $version = new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: 'content.type.article',
            storageSchemaVersion: 3,
            schemaHash: str_repeat('a', 64),
            fieldDefinitions: $fields,
            projectionContract: $projection,
            safeMetadata: ['label' => 'Article'],
            createdBy: 'user:1',
            correlationId: 'request:1',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );

        self::assertSame('content.type.article', $version->storageSchemaRef);
        self::assertSame(
            ['version', 'title_field', 'snippet_field', 'searchable_fields'],
            array_keys($projection->toArray()),
        );
        self::assertSame('integer', $projection->fieldType('priority'));

        $page = new ContentTypePage(
            [new ContentType($typeKey, 1)],
            new ContentTypeKey('event'),
        );

        self::assertCount(1, $page->items);
        self::assertSame(100, (new ContentTypeQuery(limit: 100))->limit);
    }

    public function testProjectionRejectsUnknownKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentProjectionContract::fromArray(
            [
                'version' => 1,
                'title_field' => 'title',
                'snippet_field' => null,
                'searchable_fields' => ['title'],
                'callback' => 'unsafe',
            ],
            $this->fields(),
        );
    }

    public function testProjectionRejectsPrivateOrNonStringTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentProjectionContract(
            version: 1,
            titleField: 'secret',
            snippetField: null,
            searchableFields: ['secret'],
            fieldDefinitions: $this->fields(),
        );
    }

    public function testTypeVersionRejectsProjectionBuiltFromDifferentFieldVisibility(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $typeKey = new ContentTypeKey('article');
        $exactFields = $this->fields();
        $driftedFields = [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('body', 'string', ContentFieldVisibility::Public),
            new ContentFieldDefinition('priority', 'integer', ContentFieldVisibility::Public),
            new ContentFieldDefinition('featured', 'boolean', ContentFieldVisibility::Public),
            new ContentFieldDefinition('secret', 'string', ContentFieldVisibility::Public),
        ];

        new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('c', 64),
            fieldDefinitions: $exactFields,
            projectionContract: new ContentProjectionContract(
                version: 1,
                titleField: 'title',
                snippetField: 'body',
                searchableFields: ['title', 'secret'],
                fieldDefinitions: $driftedFields,
            ),
            safeMetadata: ['label' => 'Article'],
            createdBy: 'user:1',
            correlationId: 'request:drift',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    public function testUnsafeTypeMetadataKeyFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $typeKey = new ContentTypeKey('article');
        $fields = $this->fields();

        new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('d', 64),
            fieldDefinitions: $fields,
            projectionContract: new ContentProjectionContract(
                version: 1,
                titleField: 'title',
                snippetField: 'body',
                searchableFields: ['title', 'body'],
                fieldDefinitions: $fields,
            ),
            safeMetadata: ['raw_values' => 'TOP SECRET'],
            createdBy: 'user:1',
            correlationId: 'request:unsafe-metadata',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    public function testFieldConstraintsUseOnlyTheFrozenPropertyShape(): void
    {
        $string = new ContentFieldDefinition(
            'title',
            'string',
            ContentFieldVisibility::Public,
            true,
            ['max_length' => 120, 'min_length' => 1],
        );
        $integer = new ContentFieldDefinition(
            'priority',
            'integer',
            ContentFieldVisibility::Public,
            false,
            ['max' => 100, 'min' => -100],
        );

        self::assertSame(['max_length' => 120, 'min_length' => 1], $string->constraints);
        self::assertSame(['max' => 100, 'min' => -100], $integer->constraints);
    }

    public function testStringConstraintAcceptsExactFrozenUnicodeBound(): void
    {
        $field = new ContentFieldDefinition(
            'body',
            'string',
            ContentFieldVisibility::Public,
            false,
            ['max_length' => ContentFieldDefinition::MAX_STRING_CODE_POINTS],
        );

        self::assertSame(65_536, $field->constraints['max_length']);
    }

    public function testStringConstraintAboveFrozenUnicodeBoundFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentFieldDefinition(
            'body',
            'string',
            ContentFieldVisibility::Public,
            false,
            ['max_length' => ContentFieldDefinition::MAX_STRING_CODE_POINTS + 1],
        );
    }

    public function testStringValueUsesUnicodeCodePointsRatherThanBytes(): void
    {
        $value = str_repeat('Ж', ContentFieldDefinition::MAX_STRING_CODE_POINTS);

        ContentFieldDefinition::assertStringValueWithinFrozenBound($value);
        self::assertGreaterThan(ContentFieldDefinition::MAX_STRING_CODE_POINTS, strlen($value));
    }

    public function testStringValueAboveFrozenUnicodeBoundFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentFieldDefinition::assertStringValueWithinFrozenBound(
            str_repeat('Ж', ContentFieldDefinition::MAX_STRING_CODE_POINTS + 1),
        );
    }

    public function testSafeTypeMetadataUsesExactNullableTypes(): void
    {
        $version = $this->typeVersionWithMetadata([
            'label' => 'Article',
            'plural_label' => null,
            'description' => 'Public-safe description',
            'icon' => 'document',
            'group' => 'Editorial',
            'sort' => 100,
            'hidden' => false,
        ]);

        self::assertSame(100, $version->safeMetadata['sort']);
        self::assertFalse($version->safeMetadata['hidden']);
    }

    public function testSafeTypeMetadataWrongTypeFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->typeVersionWithMetadata(['sort' => '100']);
    }

    public function testExecutableSafeTypeMetadataFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->typeVersionWithMetadata(['icon' => 'javascript:alert(1)']);
    }

    public function testControlCharacterSafeTypeMetadataFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->typeVersionWithMetadata(['label' => "Article\nInjected"]);
    }

    public function testSafeTypeMetadataAboveSixteenKibFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->typeVersionWithMetadata(['description' => str_repeat('a', 16_384)]);
    }

    public function testBooleanOrExecutableFieldConstraintFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentFieldDefinition(
            'featured',
            'boolean',
            ContentFieldVisibility::Public,
            false,
            ['callback' => 'run'],
        );
    }

    /**
     * @return list<ContentFieldDefinition>
     */
    private function fields(): array
    {
        return [
            new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true),
            new ContentFieldDefinition('body', 'string', ContentFieldVisibility::Public),
            new ContentFieldDefinition('priority', 'integer', ContentFieldVisibility::Public),
            new ContentFieldDefinition('featured', 'boolean', ContentFieldVisibility::Public),
            new ContentFieldDefinition('secret', 'string', ContentFieldVisibility::Private),
        ];
    }

    /**
     * @param array<string, mixed> $safeMetadata
     */
    private function typeVersionWithMetadata(array $safeMetadata): ContentTypeVersion
    {
        $typeKey = new ContentTypeKey('article');
        $fields = $this->fields();

        return new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('e', 64),
            fieldDefinitions: $fields,
            projectionContract: new ContentProjectionContract(
                version: 1,
                titleField: 'title',
                snippetField: 'body',
                searchableFields: ['title', 'body'],
                fieldDefinitions: $fields,
            ),
            safeMetadata: $safeMetadata,
            createdBy: 'user:1',
            correlationId: 'request:metadata',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }
}
