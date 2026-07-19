<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use DateTimeImmutable;
use Larena\Content\Enums\ContentStatus;
use Larena\Content\Enums\ContentVisibility;
use Larena\Content\ValueObjects\ContentItemRef;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentRevision;
use Larena\Content\ValueObjects\ContentRevisionPage;
use Larena\Content\ValueObjects\ContentRevisionQuery;
use Larena\Content\ValueObjects\ContentSlug;
use Larena\Content\ValueObjects\ContentTypeKey;
use PHPUnit\Framework\TestCase;

final class ContentRevisionContractTest extends TestCase
{
    public function testRevisionContainsExactStorageReferencesButNoValueCopy(): void
    {
        $revision = $this->revision();

        self::assertSame('content.type.article', $revision->storageSchemaRef);
        self::assertSame(8, $revision->storageRecordVersion);
        self::assertObjectNotHasProperty('values', $revision);
        self::assertObjectNotHasProperty('fileBytes', $revision);

        $page = new ContentRevisionPage($revision->itemRef, [$revision], 3);
        $query = new ContentRevisionQuery($revision->itemRef, afterRevision: 1, limit: 25);

        self::assertCount(1, $page->items);
        self::assertSame(1, $query->afterRevision);
    }

    public function testMismatchedStorageSchemaFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->revision('content.type.event');
    }

    public function testRevisionAcceptsFrozenAttachmentLimit(): void
    {
        self::assertSame(
            ContentRevision::MAX_ATTACHMENTS,
            $this->revision(attachmentCount: ContentRevision::MAX_ATTACHMENTS)->attachmentCount,
        );
    }

    public function testRevisionRejectsAttachmentCountAboveFrozenLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->revision(attachmentCount: ContentRevision::MAX_ATTACHMENTS + 1);
    }

    private function revision(
        string $schemaRef = 'content.type.article',
        int $attachmentCount = 0,
    ): ContentRevision
    {
        return new ContentRevision(
            itemRef: ContentItemRef::fromUuid('018f6d52-4ef8-7bc2-9c71-3f2f4c164001'),
            revision: 2,
            typeKey: new ContentTypeKey('article'),
            locale: new ContentLocale(),
            typeVersion: 1,
            storageSchemaRef: $schemaRef,
            storageSchemaVersion: 5,
            storageRecordRef: 'record-018f6d524ef87bc29c713f2f4c164001',
            storageRecordVersion: 8,
            slug: new ContentSlug('article-one'),
            status: ContentStatus::Draft,
            visibility: ContentVisibility::Public,
            attachmentCount: $attachmentCount,
            createdBy: 'user:1',
            correlationId: 'request:1',
            createdAt: new DateTimeImmutable('2026-07-19T00:00:00Z'),
        );
    }
}
