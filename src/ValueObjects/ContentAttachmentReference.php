<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentAttachmentReference
{
    public function __construct(
        public ContentItemRef $itemRef,
        public int $revision,
        public int $position,
        public string $logicalFileRef,
        public string $role,
    ) {
        if ($revision < 1) {
            throw new \InvalidArgumentException('Content attachment revisions must be positive.');
        }

        ContentAttachmentPlacement::assertPosition($position);
        ContentAttachmentPlacement::assertLogicalFileRef($logicalFileRef);
        ContentAttachmentPlacement::assertRole($role);
    }

    public function placement(): ContentAttachmentPlacement
    {
        return new ContentAttachmentPlacement(
            logicalFileRef: $this->logicalFileRef,
            role: $this->role,
            position: $this->position,
        );
    }
}
