<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ContentAttachmentPlacement
{
    public function __construct(
        public string $logicalFileRef,
        public string $role,
        public int $position,
    ) {
        self::assertLogicalFileRef($logicalFileRef);
        self::assertRole($role);
        self::assertPosition($position);
    }

    public static function assertLogicalFileRef(string $logicalFileRef): void
    {
        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',
            $logicalFileRef,
        ) !== 1) {
            throw new \InvalidArgumentException(
                'Logical Filesystem references must be canonical lowercase bare UUIDs.',
            );
        }
    }

    public static function assertRole(string $role): void
    {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $role) !== 1) {
            throw new \InvalidArgumentException('Content attachment roles must be stable lowercase identifiers.');
        }
    }

    public static function assertPosition(int $position): void
    {
        if ($position < 0) {
            throw new \InvalidArgumentException('Content attachment positions cannot be negative.');
        }
    }
}
