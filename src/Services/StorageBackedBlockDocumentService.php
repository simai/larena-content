<?php

declare(strict_types=1);

namespace Larena\Content\Services;

use JsonException;
use Larena\Content\Contracts\BlockDocumentService;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Runtime\BlockDocumentNormalizer;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\ValueObjects\BlockDocumentRevision;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageRejected;

final readonly class StorageBackedBlockDocumentService implements BlockDocumentService
{
    public const STORAGE_SCHEMA_ID = 'content.block_document';

    public function __construct(
        private VersionedStorage $storage,
        private BlockDocumentNormalizer $normalizer = new BlockDocumentNormalizer(),
        private ContentCanonicalJson $json = new ContentCanonicalJson(),
    ) {
    }

    public function installSchema(string $actor): StorageSchemaVersionRef
    {
        $ref = new StorageSchemaVersionRef(self::STORAGE_SCHEMA_ID, 1);
        try {
            $schema = $this->storage->schemaVersion($ref);
        } catch (StorageRejected $exception) {
            if ($exception->reasonCode !== 'storage_schema_version_unknown') {
                throw $exception;
            }
            $schema = $this->storage->registerSchemaVersion($this->schemaDefinition(), null, $actor);
        }
        if ($schema->ownerPackage !== 'larena/content' || $schema->ref->key() !== $ref->key()) {
            throw $this->reject('content_document_storage_schema_mismatch');
        }

        return $ref;
    }

    public function create(array $document, string $actor): BlockDocumentRevision
    {
        $document = $this->normalizer->normalize($document);
        $schema = $this->installSchema($actor);
        $result = $this->storage->create(
            $this->ownerRef((string) $document['document_id']),
            $schema,
            $this->values($document),
            $actor,
        );

        return $this->hydrate($result->version);
    }

    public function update(array $document, StorageRecordVersionRef $expected, string $actor): BlockDocumentRevision
    {
        $document = $this->normalizer->normalize($document);
        if ($expected->schemaId !== self::STORAGE_SCHEMA_ID) {
            throw $this->reject('content_document_revision_invalid');
        }
        $result = $this->storage->compareAndSwap(
            $this->ownerRef((string) $document['document_id']),
            $expected,
            $this->installSchema($actor),
            $this->values($document),
            $actor,
        );

        return $this->hydrate($result->version);
    }

    public function read(string $documentId, string $actor): ?BlockDocumentRevision
    {
        $record = $this->storage->readAdminCurrentVersion(
            self::STORAGE_SCHEMA_ID,
            $this->ownerRef($documentId),
            $actor,
        );

        return $record === null ? null : $this->hydrate($record);
    }

    public function project(string $documentId, string $actor): array
    {
        $revision = $this->read($documentId, $actor);
        if ($revision === null) {
            throw $this->reject('content_document_missing');
        }

        return $this->normalizer->publicProjection($revision->document);
    }

    /** @return array<string, mixed> */
    private function schemaDefinition(): array
    {
        return [
            'schema_id' => self::STORAGE_SCHEMA_ID,
            'owner_package' => 'larena/content',
            'fields' => [
                ['key' => 'document_json', 'type' => 'text', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['max_length' => BlockDocumentNormalizer::MAX_BYTES]],
                ['key' => 'semantic_hash', 'type' => 'string', 'type_version' => 2, 'required' => true, 'visibility' => 'protected', 'constraints' => ['min_length' => 64, 'max_length' => 64]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    private function values(array $document): array
    {
        return [
            'document_json' => $this->json->encode($document),
            'semantic_hash' => $this->normalizer->semanticHash($document),
        ];
    }

    private function hydrate(StorageRecordVersion $record): BlockDocumentRevision
    {
        try {
            $document = json_decode((string) ($record->values['document_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->reject('content_document_persisted_json_invalid');
        }
        if (!is_array($document) || array_is_list($document)) {
            throw $this->reject('content_document_persisted_json_invalid');
        }
        $document = $this->normalizer->normalize($document);
        $hash = $this->normalizer->semanticHash($document);
        if (!is_string($record->values['semantic_hash'] ?? null)
            || !hash_equals($hash, $record->values['semantic_hash'])) {
            throw $this->reject('content_document_persisted_hash_mismatch');
        }

        return new BlockDocumentRevision((string) $document['document_id'], $document, $hash, $record->ref);
    }

    private function ownerRef(string $documentId): string
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $documentId) !== 1) {
            throw $this->reject('content_document_id_invalid');
        }

        return 'content.document:'.$documentId;
    }

    private function reject(string $reason): ContentRejected
    {
        return new ContentRejected($reason, 'The Content block document was rejected.');
    }
}
