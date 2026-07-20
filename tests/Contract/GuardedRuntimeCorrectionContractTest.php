<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use DateTimeImmutable;
use Larena\Content\Access\ContentAccessOperationCatalog;
use Larena\Content\Enums\ContentFieldVisibility;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentLogicalFileInspection;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypeVersion;
use PHPUnit\Framework\TestCase;

final class GuardedRuntimeCorrectionContractTest extends TestCase
{
    private const string LOGICAL_FILE_ID = '018f6d52-4ef8-7bc2-9c71-3f2f4c164020';

    public function testFrozenCorrectionConstantsAreExplicit(): void
    {
        self::assertCount(18, ContentAccessOperationCatalog::codes());
        self::assertNotContains('content.public.read', ContentAccessOperationCatalog::codes());
        self::assertSame(65_536, ContentFieldDefinition::MAX_STRING_CODE_POINTS);
        self::assertSame(100, ContentTypeVersion::MAX_FIELDS);
        self::assertSame(16_384, ContentTypeVersion::MAX_CANONICAL_JSON_BYTES);
    }

    public function testFilesystemOwnerSurfaceMapsWithoutIdentityOrMetadataDrift(): void
    {
        $contentInspection = new ContentLogicalFileInspection(
            logicalFileRef: self::LOGICAL_FILE_ID,
            exists: true,
            available: true,
            public: false,
            safeMetadata: $this->safeFileMetadata(''),
            persistent: true,
        );

        self::assertSame(self::LOGICAL_FILE_ID, $contentInspection->logicalFileRef);
        self::assertTrue($contentInspection->physicalAvailable);
        self::assertTrue($contentInspection->persistent);
        self::assertTrue($contentInspection->isContentAttachable());
        self::assertFalse($contentInspection->isPubliclyProjectable());
        $safeMetadata = $contentInspection->safeMetadata;

        if (!array_key_exists('alt_text', $safeMetadata)) {
            self::fail('Existing logical files must expose the exact safe metadata shape.');
        }

        self::assertNull($safeMetadata['alt_text']);
        self::assertSame(
            ['public_id', 'display_name', 'mime_type', 'extension', 'size_bytes', 'alt_text'],
            array_keys($safeMetadata),
        );
    }

    public function testMissingFilesystemOwnerSurfaceKeepsEmptyMetadata(): void
    {
        $inspection = new ContentLogicalFileInspection(
            logicalFileRef: self::LOGICAL_FILE_ID,
            exists: false,
            available: false,
            public: false,
            safeMetadata: [],
            persistent: false,
        );

        self::assertFalse($inspection->exists);
        self::assertFalse($inspection->physicalAvailable);
        self::assertFalse($inspection->persistent);
        self::assertSame([], $inspection->safeMetadata);
    }

    public function testContentTypeRejectsMoreThanOneHundredFields(): void
    {
        $fields = [new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true)];

        for ($index = 1; $index <= 100; ++$index) {
            $fields[] = new ContentFieldDefinition(
                sprintf('field_%03d', $index),
                'string',
                ContentFieldVisibility::Private,
            );
        }

        $typeKey = new ContentTypeKey('article');
        $projection = new ContentProjectionContract(
            version: 1,
            titleField: 'title',
            snippetField: null,
            searchableFields: ['title'],
            fieldDefinitions: $fields,
        );

        $this->expectException(\InvalidArgumentException::class);

        new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('f', 64),
            fieldDefinitions: $fields,
            projectionContract: $projection,
            safeMetadata: ['label' => 'Article'],
            createdBy: 'user:1',
            correlationId: 'request:too-many-fields',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    public function testInvalidUtf8TypeMetadataFailsClosed(): void
    {
        $field = new ContentFieldDefinition('title', 'string', ContentFieldVisibility::Public, true);
        $typeKey = new ContentTypeKey('article');

        $this->expectException(\InvalidArgumentException::class);

        new ContentTypeVersion(
            typeKey: $typeKey,
            version: 1,
            storageSchemaRef: $typeKey->storageSchemaRef(),
            storageSchemaVersion: 1,
            schemaHash: str_repeat('f', 64),
            fieldDefinitions: [$field],
            projectionContract: new ContentProjectionContract(
                version: 1,
                titleField: 'title',
                snippetField: null,
                searchableFields: ['title'],
                fieldDefinitions: [$field],
            ),
            safeMetadata: ['label' => "\xC3\x28"],
            createdBy: 'user:1',
            correlationId: 'request:invalid-utf8',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }

    public function testCurrentImplementationReceiptsAreClonePortableAndBounded(): void
    {
        $root = dirname(__DIR__, 2);
        $context = json_decode(
            (string) file_get_contents($root.'/.larena/launch-context.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($context);
        $actionGate = $context['action_gate'] ?? null;
        $toolchain = $context['runtime_toolchain'] ?? null;
        self::assertIsArray($actionGate);
        self::assertIsArray($toolchain);
        $toolchainRef = $toolchain['report_ref'] ?? null;
        self::assertIsString($toolchainRef);
        self::assertSame('not_required', $actionGate['status'] ?? null);
        self::assertArrayNotHasKey('evidence_ref', $actionGate);
        self::assertFalse(str_starts_with($toolchainRef, '/'));
        self::assertSame(
            'docs/project-management/evidence/data-content/content-model-administration-api-v1/tests.md',
            $toolchainRef,
        );
        self::assertFileExists($root.'/'.$toolchainRef);
        self::assertFalse($context['review_completed'] ?? true);
        self::assertSame('pending', $context['independent_review_verdict'] ?? null);
        self::assertSame(
            'not_requested_in_content_owner_scope',
            $context['remote_push_status'] ?? null,
        );
    }

    /**
     * @return array{public_id: string, display_name: string, mime_type: string, extension: string, size_bytes: int, alt_text: string|null}
     */
    private function safeFileMetadata(?string $altText): array
    {
        return [
            'public_id' => '018f6d52-4ef8-7bc2-9c71-3f2f4c164021',
            'display_name' => 'Attachment',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 128,
            'alt_text' => $altText,
        ];
    }
}
