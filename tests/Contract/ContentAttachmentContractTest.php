<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\ValueObjects\ContentAttachmentReference;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLogicalFileInspection;
use Larena\Content\ValueObjects\PublicContentAttachment;
use PHPUnit\Framework\TestCase;

final class ContentAttachmentContractTest extends TestCase
{
    private const string LOGICAL_FILE_ID = '018f6d52-4ef8-7bc2-9c71-3f2f4c164010';

    private const string OTHER_LOGICAL_FILE_ID = '018f6d52-4ef8-7bc2-9c71-3f2f4c164011';

    public function testAttachmentStoresOnlyRevisionBoundLogicalIdentityRoleAndOrder(): void
    {
        $attachment = new ContentAttachmentReference(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            revision: 2,
            position: 0,
            logicalFileRef: self::LOGICAL_FILE_ID,
            role: 'hero',
        );

        self::assertSame(self::LOGICAL_FILE_ID, $attachment->logicalFileRef);
        self::assertObjectNotHasProperty('bytes', $attachment);
        self::assertObjectNotHasProperty('path', $attachment);
        self::assertObjectNotHasProperty('signedUrl', $attachment);
    }

    public function testOnlyAvailablePublicPersistentInspectionIsProjectable(): void
    {
        $inspection = $this->inspection();
        $attachment = PublicContentAttachment::fromInspection(
            reference: $this->reference(),
            inspection: $inspection,
        );

        self::assertTrue($inspection->isContentAttachable());
        self::assertTrue($inspection->isPubliclyProjectable());
        self::assertTrue($inspection->physicalAvailable);
        self::assertSame(
            ['logical_file_ref', 'role', 'position', 'metadata'],
            array_keys($attachment->toArray()),
        );
        self::assertSame(
            ['public_id', 'display_name', 'mime_type', 'extension', 'size_bytes', 'alt_text'],
            array_keys($inspection->safeMetadata),
        );
    }

    public function testEmptyAltTextIsNormalizedToNull(): void
    {
        $inspection = $this->inspection(altText: '');
        $metadata = $inspection->safeMetadata;

        if (!array_key_exists('alt_text', $metadata)) {
            self::fail('Existing logical files must expose the exact safe metadata shape.');
        }

        self::assertNull($metadata['alt_text']);
    }

    public function testSignedUrlMetadataFailsClosed(): void
    {
        $metadata = $this->safeMetadata();
        $metadata['signed_url'] = 'https://private.invalid';

        $this->expectException(\InvalidArgumentException::class);

        new ContentLogicalFileInspection(
            logicalFileRef: self::LOGICAL_FILE_ID,
            exists: true,
            available: true,
            public: true,
            safeMetadata: $metadata,
            persistent: true,
        );
    }

    public function testIncompleteExistingFilesystemMetadataFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentLogicalFileInspection(
            logicalFileRef: self::LOGICAL_FILE_ID,
            exists: true,
            available: true,
            public: true,
            safeMetadata: ['mime_type' => 'image/png'],
            persistent: true,
        );
    }

    public function testMissingInspectionRequiresEmptyMetadataAndNoPolicyState(): void
    {
        $inspection = new ContentLogicalFileInspection(
            logicalFileRef: self::LOGICAL_FILE_ID,
            exists: false,
            available: false,
            public: false,
            safeMetadata: [],
            persistent: false,
        );

        self::assertSame([], $inspection->safeMetadata);
        self::assertFalse($inspection->isContentAttachable());
        self::assertFalse($inspection->isPubliclyProjectable());
    }

    public function testPrivatePersistentInspectionIsAttachableButNotPubliclyProjectable(): void
    {
        $inspection = $this->inspection(public: false);

        self::assertTrue($inspection->isContentAttachable());
        self::assertFalse($inspection->isPubliclyProjectable());

        $this->expectException(\InvalidArgumentException::class);

        PublicContentAttachment::fromInspection($this->reference(), $inspection);
    }

    public function testNonPersistentInspectionIsNeverAttachable(): void
    {
        $inspection = $this->inspection(persistent: false);

        self::assertFalse($inspection->isContentAttachable());
        self::assertFalse($inspection->isPubliclyProjectable());
    }

    public function testInspectionForAnotherLogicalFileFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicContentAttachment::fromInspection(
            reference: $this->reference(),
            inspection: $this->inspection(logicalFileRef: self::OTHER_LOGICAL_FILE_ID),
        );
    }

    public function testPrefixedOrUppercaseLogicalIdentityFailsClosed(): void
    {
        foreach (['filesystem:logical:'.self::LOGICAL_FILE_ID, strtoupper(self::LOGICAL_FILE_ID)] as $invalid) {
            try {
                new ContentAttachmentReference(
                    itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
                    revision: 2,
                    position: 0,
                    logicalFileRef: $invalid,
                    role: 'hero',
                );
                self::fail('A non-canonical logical-file identity was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function reference(int $position = 0): ContentAttachmentReference
    {
        return new ContentAttachmentReference(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            revision: 2,
            position: $position,
            logicalFileRef: self::LOGICAL_FILE_ID,
            role: 'hero',
        );
    }

    private function inspection(
        string $logicalFileRef = self::LOGICAL_FILE_ID,
        bool $available = true,
        bool $public = true,
        bool $persistent = true,
        ?string $altText = 'Safe alt',
    ): ContentLogicalFileInspection {
        return new ContentLogicalFileInspection(
            logicalFileRef: $logicalFileRef,
            exists: true,
            available: $available,
            public: $public,
            safeMetadata: $this->safeMetadata($altText),
            persistent: $persistent,
        );
    }

    /**
     * @return array{public_id: string, display_name: string, mime_type: string, extension: string, size_bytes: int, alt_text: string|null}
     */
    private function safeMetadata(?string $altText = 'Safe alt'): array
    {
        return [
            'public_id' => '018f6d52-4ef8-7bc2-9c71-3f2f4c164099',
            'display_name' => 'Hero image',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 42,
            'alt_text' => $altText,
        ];
    }
}
