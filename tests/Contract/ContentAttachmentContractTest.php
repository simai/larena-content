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
    public function testAttachmentStoresOnlyRevisionBoundLogicalIdentityRoleAndOrder(): void
    {
        $attachment = new ContentAttachmentReference(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            revision: 2,
            position: 0,
            logicalFileRef: 'filesystem:logical:018f6d52',
            role: 'hero',
        );

        self::assertSame('filesystem:logical:018f6d52', $attachment->logicalFileRef);
        self::assertObjectNotHasProperty('bytes', $attachment);
        self::assertObjectNotHasProperty('path', $attachment);
        self::assertObjectNotHasProperty('signedUrl', $attachment);
    }

    public function testOnlyAvailablePublicInspectionIsProjectable(): void
    {
        $inspection = new ContentLogicalFileInspection(
            logicalFileRef: 'filesystem:logical:018f6d52',
            exists: true,
            available: true,
            public: true,
            safeMetadata: ['mime_type' => 'image/png', 'size_bytes' => 42],
        );
        $attachment = PublicContentAttachment::fromInspection(
            reference: $this->reference(),
            inspection: $inspection,
        );

        self::assertTrue($inspection->isPubliclyProjectable());
        self::assertSame(
            ['logical_file_ref', 'role', 'position', 'metadata'],
            array_keys($attachment->toArray()),
        );
    }

    public function testSignedUrlMetadataFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentLogicalFileInspection(
            logicalFileRef: 'filesystem:logical:018f6d52',
            exists: true,
            available: true,
            public: true,
            safeMetadata: ['signed_url' => 'https://private.invalid'],
        );
    }

    public function testPrivateInspectionCannotBecomePublicAttachment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicContentAttachment::fromInspection(
            reference: $this->reference(),
            inspection: new ContentLogicalFileInspection(
                logicalFileRef: 'filesystem:logical:018f6d52',
                exists: true,
                available: true,
                public: false,
                safeMetadata: ['mime_type' => 'image/png'],
            ),
        );
    }

    public function testInspectionForAnotherLogicalFileFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicContentAttachment::fromInspection(
            reference: $this->reference(),
            inspection: new ContentLogicalFileInspection(
                logicalFileRef: 'filesystem:logical:another',
                exists: true,
                available: true,
                public: true,
                safeMetadata: ['mime_type' => 'image/png'],
            ),
        );
    }

    public function testUnknownFilesystemMetadataFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentLogicalFileInspection(
            logicalFileRef: 'filesystem:logical:018f6d52',
            exists: true,
            available: true,
            public: true,
            safeMetadata: ['private_url' => 'https://private.invalid'],
        );
    }

    private function reference(): ContentAttachmentReference
    {
        return new ContentAttachmentReference(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            revision: 2,
            position: 0,
            logicalFileRef: 'filesystem:logical:018f6d52',
            role: 'hero',
        );
    }
}
