<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

use Larena\Search\Contracts\SearchProjection;

final readonly class ContentSearchProjection
{
    public const PROVIDER_ID = 'content.published_items';

    public const ACCESS_SCOPE = 'public';

    /**
     * @var array{
     *     type_key: string,
     *     slug: string,
     *     locale: string,
     *     content_revision: int,
     *     projection_version: int
     * }
     */
    public array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public string $providerId,
        public ContentItemRef $sourceRef,
        public int $sourceRevision,
        public string $title,
        public string $locator,
        public string $snippet,
        public ContentLocale $locale,
        public string $accessScope,
        public string $searchableText,
        array $payload,
    ) {
        if ($providerId !== self::PROVIDER_ID || $accessScope !== self::ACCESS_SCOPE) {
            throw new \InvalidArgumentException('Content Search identity and access scope are frozen.');
        }

        if ($sourceRevision < 1) {
            throw new \InvalidArgumentException('Content Search source revisions must be positive.');
        }

        if ($title === '' || self::unicodeLength($title) > 500) {
            throw new \InvalidArgumentException('Content Search titles must be non-empty and at most 500 code points.');
        }

        if (self::unicodeLength($snippet) > 240 || self::unicodeLength($searchableText) > 32000) {
            throw new \InvalidArgumentException('Content Search text exceeds its frozen Unicode bounds.');
        }

        $expectedLocator = sprintf(
            '/content/%s/%s?locale=%s',
            $payload['type_key'],
            $payload['slug'],
            $payload['locale'],
        );

        if ($locator !== $expectedLocator) {
            throw new \InvalidArgumentException('Content Search locators must use the frozen public route shape.');
        }

        $payloadKeys = array_keys($payload);

        if (
            $payloadKeys !== [
                'type_key',
                'slug',
                'locale',
                'content_revision',
                'projection_version',
            ]
            || !is_string($payload['type_key'] ?? null)
            || !is_string($payload['slug'] ?? null)
            || !is_string($payload['locale'] ?? null)
            || !is_int($payload['content_revision'] ?? null)
            || !is_int($payload['projection_version'] ?? null)
            || $payload['content_revision'] !== $sourceRevision
            || $payload['locale'] !== $locale->value
        ) {
            throw new \InvalidArgumentException('Content Search payload must contain only the exact frozen metadata.');
        }

        $this->payload = [
            'type_key' => $payload['type_key'],
            'slug' => $payload['slug'],
            'locale' => $payload['locale'],
            'content_revision' => $payload['content_revision'],
            'projection_version' => $payload['projection_version'],
        ];
    }

    public static function fromPublished(
        PublishedContentProjection $projection,
    ): self {
        $contract = $projection->projectionContract();

        if ($projection->projectionVersion !== $contract->version) {
            throw new \InvalidArgumentException('Published and Search projection contract versions must match.');
        }

        $titleValue = $projection->publicFields[$contract->titleField] ?? null;

        if (!is_string($titleValue)) {
            throw new \InvalidArgumentException('The published title field must be a string.');
        }

        $title = self::trimUnicode($titleValue);

        if ($title === '' || self::unicodeLength($title) > 500) {
            throw new \InvalidArgumentException('The published title must be non-blank and at most 500 code points.');
        }

        $snippet = '';

        if ($contract->snippetField !== null) {
            $snippetValue = $projection->publicFields[$contract->snippetField] ?? null;

            if ($snippetValue !== null && !is_string($snippetValue)) {
                throw new \InvalidArgumentException('The published snippet field must be a string or null.');
            }

            if (is_string($snippetValue)) {
                $snippet = self::truncate(self::trimUnicode($snippetValue), 240);
            }
        }

        $searchableParts = [];

        foreach ($contract->searchableFields as $fieldKey) {
            $value = $projection->publicFields[$fieldKey] ?? null;

            if ($value === null) {
                continue;
            }

            $searchableParts[] = match ($contract->fieldType($fieldKey)) {
                'string' => is_string($value)
                    ? self::trimUnicode($value)
                    : throw new \InvalidArgumentException('A searchable string field has an invalid value.'),
                'integer' => is_int($value)
                    ? (string) $value
                    : throw new \InvalidArgumentException('A searchable integer field has an invalid value.'),
                'boolean' => is_bool($value)
                    ? ($value ? 'true' : 'false')
                    : throw new \InvalidArgumentException('A searchable boolean field has an invalid value.'),
                default => throw new \LogicException('Unsupported frozen Content field type.'),
            };
        }

        $locator = sprintf(
            '/content/%s/%s?locale=%s',
            $projection->typeKey->value,
            $projection->slug->value,
            $projection->locale->value,
        );

        $payload = [
            'type_key' => $projection->typeKey->value,
            'slug' => $projection->slug->value,
            'locale' => $projection->locale->value,
            'content_revision' => $projection->publishedRevision,
            'projection_version' => $projection->projectionVersion,
        ];

        return new self(
            providerId: self::PROVIDER_ID,
            sourceRef: $projection->itemRef,
            sourceRevision: $projection->publishedRevision,
            title: $title,
            locator: $locator,
            snippet: $snippet,
            locale: $projection->locale,
            accessScope: self::ACCESS_SCOPE,
            searchableText: self::truncate(implode(' ', $searchableParts), 32000),
            payload: $payload,
        );
    }

    /**
     * @return array{
     *     provider_id: string,
     *     source_ref: string,
     *     source_revision: int,
     *     title: string,
     *     locator: string,
     *     snippet: string,
     *     locale: string,
     *     access_scope: string,
     *     searchable_text: string,
     *     payload: array{
     *         type_key: string,
     *         slug: string,
     *         locale: string,
     *         content_revision: int,
     *         projection_version: int
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'provider_id' => $this->providerId,
            'source_ref' => $this->sourceRef->value,
            'source_revision' => $this->sourceRevision,
            'title' => $this->title,
            'locator' => $this->locator,
            'snippet' => $this->snippet,
            'locale' => $this->locale->value,
            'access_scope' => $this->accessScope,
            'searchable_text' => $this->searchableText,
            'payload' => $this->payload,
        ];
    }

    public function toSearchProjection(): SearchProjection
    {
        return new SearchProjection(
            providerId: $this->providerId,
            sourceRef: $this->sourceRef->value,
            sourceRevision: $this->sourceRevision,
            title: $this->title,
            locator: $this->locator,
            snippet: $this->snippet,
            locale: $this->locale->value,
            accessScope: $this->accessScope,
            searchableText: $this->searchableText,
            payload: $this->payload,
        );
    }

    private static function trimUnicode(string $value): string
    {
        $trimmed = preg_replace('/\A\s+|\s+\z/u', '', $value);

        if ($trimmed === null) {
            throw new \InvalidArgumentException('Content Search text must be valid UTF-8.');
        }

        return $trimmed;
    }

    private static function unicodeLength(string $value): int
    {
        $matched = preg_match_all('/./us', $value, $characters);

        if ($matched === false) {
            throw new \InvalidArgumentException('Content Search text must be valid UTF-8.');
        }

        return $matched;
    }

    private static function truncate(string $value, int $maximumCodePoints): string
    {
        $matched = preg_match_all('/./us', $value, $characters);

        if ($matched === false) {
            throw new \InvalidArgumentException('Content Search text must be valid UTF-8.');
        }

        if ($matched <= $maximumCodePoints) {
            return $value;
        }

        return implode('', array_slice($characters[0], 0, $maximumCodePoints));
    }
}
