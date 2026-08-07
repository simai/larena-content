<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\BlockDocumentRevision;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;

interface BlockDocumentService
{
    public function installSchema(string $actor): StorageSchemaVersionRef;

    /** @param array<string, mixed> $document */
    public function create(array $document, string $actor): BlockDocumentRevision;

    /** @param array<string, mixed> $document */
    public function update(array $document, StorageRecordVersionRef $expected, string $actor): BlockDocumentRevision;

    public function read(string $documentId, string $actor): ?BlockDocumentRevision;

    /** @return array<string, mixed> */
    public function project(string $documentId, string $actor): array;
}
