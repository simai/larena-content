<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class PublicContentAttachment
{
    /**
     * @param array<string, bool|float|int|string|null> $metadata
     */
    private function __construct(
        private ContentItemRef $sourceItemRef,
        private int $sourceRevision,
        public string $logicalFileRef,
        public string $role,
        public int $position,
        public array $metadata = [],
    ) {
        if ($sourceRevision < 1) {
            throw new \InvalidArgumentException('A public Content attachment requires an exact positive revision.');
        }

        ContentAttachmentPlacement::assertLogicalFileRef($logicalFileRef);
        ContentAttachmentPlacement::assertRole($role);
        ContentAttachmentPlacement::assertPosition($position);
        ContentLogicalFileInspection::assertSafeMetadata($metadata);
    }

    public static function fromInspection(
        ContentAttachmentReference $reference,
        ContentLogicalFileInspection $inspection,
    ): self {
        if ($reference->logicalFileRef !== $inspection->logicalFileRef) {
            throw new \InvalidArgumentException(
                'The logical Filesystem inspection must belong to the exact Content attachment reference.',
            );
        }

        if (!$inspection->isPubliclyProjectable()) {
            throw new \InvalidArgumentException(
                'Only an available public logical file inspection may enter a public Content projection.',
            );
        }

        return new self(
            sourceItemRef: $reference->itemRef,
            sourceRevision: $reference->revision,
            logicalFileRef: $inspection->logicalFileRef,
            role: $reference->role,
            position: $reference->position,
            metadata: $inspection->safeMetadata,
        );
    }

    public function belongsToRevision(ContentItemRef $itemRef, int $revision): bool
    {
        return $this->sourceItemRef->value === $itemRef->value
            && $this->sourceRevision === $revision;
    }

    /**
     * @return array{
     *     logical_file_ref: string,
     *     role: string,
     *     position: int,
     *     metadata: array<string, bool|float|int|string|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'logical_file_ref' => $this->logicalFileRef,
            'role' => $this->role,
            'position' => $this->position,
            'metadata' => $this->metadata,
        ];
    }
}
