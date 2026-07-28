<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class SiteStructureNode
{
    public const int MAX_LABEL_BYTES = 200;

    public function __construct(
        public string $nodeRef,
        public ?string $parentRef,
        public int $position,
        public string $label,
        public bool $visible,
        public string $targetType,
        public ?ContentItemRef $contentItemRef = null,
        public ?string $externalUrl = null,
    ) {
        self::assertUuid($nodeRef, 'node');
        if ($parentRef !== null) {
            self::assertUuid($parentRef, 'parent');
        }
        if ($position < 0 || $position > 199) {
            throw new \InvalidArgumentException('Site-structure node positions must be between zero and 199.');
        }
        if (
            $label === ''
            || strlen($label) > self::MAX_LABEL_BYTES
            || preg_match('//u', $label) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
        ) {
            throw new \InvalidArgumentException('Site-structure labels must be bounded control-free UTF-8.');
        }
        if ($targetType === 'content') {
            if (!$contentItemRef instanceof ContentItemRef || $externalUrl !== null) {
                throw new \InvalidArgumentException('Content navigation nodes require one canonical Content item reference.');
            }
        } elseif ($targetType === 'external') {
            if ($contentItemRef !== null || $externalUrl === null) {
                throw new \InvalidArgumentException('External navigation nodes require one HTTPS URL.');
            }
            self::assertExternalUrl($externalUrl);
        } else {
            throw new \InvalidArgumentException('Unknown site-structure target type.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'node_ref' => $this->nodeRef,
            'parent_ref' => $this->parentRef,
            'position' => $this->position,
            'label' => $this->label,
            'visible' => $this->visible,
            'target_type' => $this->targetType,
            'content_item_ref' => $this->contentItemRef?->value,
            'external_url' => $this->externalUrl,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        $itemRef = $value['content_item_ref'] ?? null;

        return new self(
            nodeRef: self::string($value, 'node_ref'),
            parentRef: self::nullableString($value, 'parent_ref'),
            position: self::integer($value, 'position'),
            label: self::string($value, 'label'),
            visible: self::boolean($value, 'visible'),
            targetType: self::string($value, 'target_type'),
            contentItemRef: $itemRef === null ? null : new ContentItemRef(self::string($value, 'content_item_ref')),
            externalUrl: self::nullableString($value, 'external_url'),
        );
    }

    private static function assertUuid(string $value, string $label): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid site-structure %s UUID.', $label));
        }
    }

    private static function assertExternalUrl(string $url): void
    {
        if (strlen($url) > 2048 || preg_match('//u', $url) !== 1 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new \InvalidArgumentException('External navigation URL is invalid.');
        }
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('External navigation targets must be absolute HTTPS URLs without credentials or fragments.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(array $value, string $key): string
    {
        if (!array_key_exists($key, $value) || !is_string($value[$key])) {
            throw new \InvalidArgumentException('Invalid site-structure node field.');
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (!array_key_exists($key, $value) || (!is_string($value[$key]) && $value[$key] !== null)) {
            throw new \InvalidArgumentException('Invalid site-structure node field.');
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value */
    private static function integer(array $value, string $key): int
    {
        if (!array_key_exists($key, $value) || !is_int($value[$key])) {
            throw new \InvalidArgumentException('Invalid site-structure node field.');
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value */
    private static function boolean(array $value, string $key): bool
    {
        if (!array_key_exists($key, $value) || !is_bool($value[$key])) {
            throw new \InvalidArgumentException('Invalid site-structure node field.');
        }

        return $value[$key];
    }
}
