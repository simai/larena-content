<?php

declare(strict_types=1);

namespace Larena\Content\Dataview;

use Larena\Content\Contracts\ContentDataviewSourceProvider;
use Larena\Dataview\Contracts\DataviewSourceDescriptor;

final readonly class MaterializedContentDataviewSourceProvider implements ContentDataviewSourceProvider
{
    /**
     * @var list<array<string, bool|int|string|null>>
     */
    private array $materializedRows;

    /**
     * @param array<array-key, mixed> $rows
     */
    public function __construct(array $rows)
    {
        if (!array_is_list($rows) || count($rows) > 100) {
            throw new \InvalidArgumentException(
                'A Content Dataview provider accepts at most 100 materialized rows.',
            );
        }

        $expectedKeys = [
            'item_ref',
            'type_key',
            'locale',
            'current_revision',
            'current_slug',
            'current_status',
            'current_visibility',
            'published_revision',
            'published_slug',
            'published_at',
        ];
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $expectedKeys) {
                throw new \InvalidArgumentException(
                    'Content Dataview rows must use the exact frozen field order.',
                );
            }
            foreach ($row as $value) {
                if (!($value === null || is_bool($value) || is_int($value) || is_string($value))) {
                    throw new \InvalidArgumentException(
                        'Content Dataview rows accept scalar presentation values only.',
                    );
                }
            }

            $normalized[] = $row;
        }

        $this->materializedRows = $normalized;
    }

    public function descriptor(): DataviewSourceDescriptor
    {
        return ContentDataviewContract::descriptor();
    }

    public function rows(): array
    {
        return $this->materializedRows;
    }
}
