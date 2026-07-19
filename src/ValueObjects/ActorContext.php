<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class ActorContext
{
    public function __construct(
        public string $actorType,
        public string $actorRef,
        public string $correlationId,
    ) {
        self::assertIdentifier($actorType, 64, 'actor type');
        self::assertSafeReference($actorRef, 'actor reference');
        self::assertSafeReference($correlationId, 'correlation id');
    }

    private static function assertIdentifier(string $value, int $maximumLength, string $label): void
    {
        if (
            strlen($value) > $maximumLength
            || preg_match('/\A[a-z][a-z0-9._-]*\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid Content %s.', $label));
        }
    }

    private static function assertSafeReference(string $value, string $label): void
    {
        if (
            $value === ''
            || strlen($value) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid Content %s.', $label));
        }
    }
}
