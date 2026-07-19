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
        if ($actorType !== 'user') {
            throw new \InvalidArgumentException('Content protected operations require actor type user.');
        }

        if (preg_match('/\Auser:admin_identity:[1-9][0-9]*\z/D', $actorRef) !== 1) {
            throw new \InvalidArgumentException(
                'Content actor references must use user:admin_identity:<positive integer>.',
            );
        }

        self::assertSafeReference($correlationId, 'correlation id');
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
