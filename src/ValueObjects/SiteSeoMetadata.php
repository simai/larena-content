<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class SiteSeoMetadata
{
    private const array ROBOTS = [
        'index,follow',
        'index,nofollow',
        'noindex,follow',
        'noindex,nofollow',
    ];

    public function __construct(
        public ContentItemRef $itemRef,
        public ?string $canonicalPath,
        public ?string $seoTitle,
        public ?string $description,
        public string $robots = 'index,follow',
    ) {
        if ($canonicalPath !== null && preg_match('/\A\/(?:[a-z0-9][a-z0-9._~-]*\/)*[a-z0-9][a-z0-9._~-]*\z/D', $canonicalPath) !== 1) {
            throw new \InvalidArgumentException('Canonical paths must be normalized absolute paths without query or fragment.');
        }
        self::assertText($seoTitle, 255, 'SEO title');
        self::assertText($description, 500, 'SEO description');
        if (!in_array($robots, self::ROBOTS, true)) {
            throw new \InvalidArgumentException('Unknown robots policy.');
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'item_ref' => $this->itemRef->value,
            'canonical_path' => $this->canonicalPath,
            'seo_title' => $this->seoTitle,
            'description' => $this->description,
            'robots' => $this->robots,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            new ContentItemRef(self::string($value, 'item_ref')),
            self::nullableString($value, 'canonical_path'),
            self::nullableString($value, 'seo_title'),
            self::nullableString($value, 'description'),
            self::string($value, 'robots'),
        );
    }

    private static function assertText(?string $value, int $maximumBytes, string $label): void
    {
        if ($value === null) {
            return;
        }
        if ($value === '' || strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('%s must be bounded control-free UTF-8.', $label));
        }
    }

    /** @param array<string, mixed> $value */
    private static function string(array $value, string $key): string
    {
        if (!array_key_exists($key, $value) || !is_string($value[$key])) {
            throw new \InvalidArgumentException('Invalid SEO metadata field.');
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (!array_key_exists($key, $value) || (!is_string($value[$key]) && $value[$key] !== null)) {
            throw new \InvalidArgumentException('Invalid SEO metadata field.');
        }

        return $value[$key];
    }
}
