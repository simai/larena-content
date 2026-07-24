<?php

declare(strict_types=1);

namespace Larena\Content\Search;

use Larena\Content\Contracts\ContentProductSearchProjector;
use Larena\Content\ValueObjects\ContentTypeKey;
use LogicException;

/**
 * Declares Content types whose Search projection is owned by an explicit
 * product adapter.
 *
 * Content remains the lifecycle and public-projection owner. Delegation only
 * suppresses the generic Search document so a product such as Docara can
 * provide its canonical locator and search text without creating a duplicate.
 */
final class ContentSearchProjectionDelegationRegistry
{
    /** @var array<string, ContentProductSearchProjector> */
    private array $delegated = [];

    public function delegate(
        ContentTypeKey|string $typeKey,
        ContentProductSearchProjector $projector,
    ): bool
    {
        $key = $typeKey instanceof ContentTypeKey
            ? $typeKey->value
            : (new ContentTypeKey($typeKey))->value;

        if (isset($this->delegated[$key])) {
            if ($this->delegated[$key] !== $projector) {
                throw new LogicException('content_search_projection_delegation_conflict');
            }

            return false;
        }

        if ($projector->providerId() === ContentSearchContract::PROVIDER_ID) {
            throw new \InvalidArgumentException(
                'A delegated product Search projector must use its own provider id.',
            );
        }

        $this->delegated[$key] = $projector;

        return true;
    }

    public function projector(
        ContentTypeKey|string $typeKey,
    ): ?ContentProductSearchProjector {
        $key = $typeKey instanceof ContentTypeKey
            ? $typeKey->value
            : (new ContentTypeKey($typeKey))->value;

        return $this->delegated[$key] ?? null;
    }

    public function isDelegated(ContentTypeKey|string $typeKey): bool
    {
        $key = $typeKey instanceof ContentTypeKey
            ? $typeKey->value
            : (new ContentTypeKey($typeKey))->value;

        return isset($this->delegated[$key]);
    }

    /** @return list<string> */
    public function delegatedTypeKeys(): array
    {
        $keys = array_keys($this->delegated);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
