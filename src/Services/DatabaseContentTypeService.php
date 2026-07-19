<?php

declare(strict_types=1);

namespace Larena\Content\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use JsonException;
use Larena\Content\Access\ContentAuthorizer;
use Larena\Content\Audit\ContentAuditEmitter;
use Larena\Content\Audit\ContentAuditPayload;
use Larena\Content\Contracts\ContentClock;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Persistence\DatabaseContentRepository;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\Runtime\ContentParticipantGuard;
use Larena\Content\Runtime\ContentSchemaMapper;
use Larena\Content\Storage\ContentStorageGateway;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentType;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypePage;
use Larena\Content\ValueObjects\ContentTypeQuery;
use Larena\Content\ValueObjects\ContentTypeVersion;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Throwable;

final readonly class DatabaseContentTypeService implements ContentTypeService
{
    public function __construct(
        private DatabaseContentRepository $repository,
        private ContentAuthorizer $authorizer,
        private ContentParticipantGuard $participants,
        private ContentStorageGateway $storage,
        private ContentSchemaMapper $schemas,
        private ContentInputGuard $input,
        private ContentCanonicalJson $canonicalJson,
        private ContentAuditEmitter $audit,
        private ContentClock $clock,
    ) {
    }

    public function create(
        ContentTypeKey $typeKey,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
        ActorContext $actor,
    ): ContentType {
        $connection = $this->preflightProtected(
            $actor,
            'content.type.create',
            ['storage.schema.create'],
        );

        try {
            $this->input->assertFields($fields);
            $this->input->assertProjectionContract($projectionContract);
            try {
                $projectionContract->assertExactFieldDefinitions($fields);
            } catch (\InvalidArgumentException $exception) {
                throw new ContentRejected(
                    'projection_contract_invalid',
                    'The projection contract does not match the submitted Content fields.',
                    $exception,
                );
            }
            $this->input->assertSafeTypeMetadata($safeMetadata);
            $this->repository->assertCompleteCompatible();

            return $connection->transaction(function () use (
                $typeKey,
                $fields,
                $projectionContract,
                $safeMetadata,
                $actor,
            ): ContentType {
                if ($this->repository->typeRow($typeKey->value, true) !== null) {
                    throw new ContentRejected(
                        'type_already_exists',
                        'The Content type already exists.',
                    );
                }

                $schema = $this->storage->registerTypeSchema($typeKey, $fields, $actor);
                $now = $this->timestamp($this->clock->now());

                $this->repository->insertTypeHead([
                    'type_key' => $typeKey->value,
                    'current_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->repository->insertTypeVersion([
                    'type_key' => $typeKey->value,
                    'version' => 1,
                    'storage_schema_ref' => $schema->ref->schemaId,
                    'storage_schema_version' => $schema->ref->version,
                    'schema_hash' => $schema->definitionHash,
                    'projection_contract' => $this->canonicalJson->encode($projectionContract->toArray()),
                    'safe_metadata' => $this->canonicalJson->encode($safeMetadata),
                    'created_by' => $actor->actorRef,
                    'correlation_id' => $actor->correlationId,
                    'created_at' => $now,
                ]);
                $this->audit->emit(
                    'content.type.created',
                    $actor,
                    $this->typeSubject($typeKey),
                    ContentAuditPayload::from([
                        'operation' => 'content.type.create',
                        'type_key' => $typeKey->value,
                        'new_revision' => 1,
                        'field_count' => count($fields),
                    ]),
                );

                return new ContentType($typeKey, 1);
            }, 3);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.create',
                $exception,
                $this->typeSubject($typeKey),
                ['type_key' => $typeKey->value],
            );

            throw $exception;
        }
    }

    public function read(ContentTypeKey $typeKey, ActorContext $actor): ContentType
    {
        $connection = $this->preflightProtected($actor, 'content.type.read');

        try {
            $this->repository->assertCompleteCompatible();
            $row = $this->repository->typeRow($typeKey->value);

            if ($row === null) {
                throw new ContentRejected('type_not_found', 'The Content type does not exist.');
            }

            return $this->hydrateType($row);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.read',
                $exception,
                $this->typeSubject($typeKey),
                ['type_key' => $typeKey->value],
            );

            throw $exception;
        }
    }

    public function version(
        ContentTypeKey $typeKey,
        int $version,
        ActorContext $actor,
    ): ContentTypeVersion {
        $connection = $this->preflightProtected($actor, 'content.type.read');

        try {
            $this->input->assertMutableRevision($version);
            $this->repository->assertCompleteCompatible();
            $row = $this->repository->typeVersionRow($typeKey->value, $version);

            if ($row === null) {
                throw new ContentRejected(
                    'type_version_not_found',
                    'The exact Content type version does not exist.',
                );
            }

            return $this->hydrateTypeVersion($row);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.read',
                $exception,
                $this->typeSubject($typeKey),
                [
                    'type_key' => $typeKey->value,
                    'current_revision' => max(0, $version),
                ],
            );

            throw $exception;
        }
    }

    public function list(ContentTypeQuery $query, ActorContext $actor): ContentTypePage
    {
        $connection = $this->participants->assertSharedConnection();
        $this->input->assertActor($actor);

        try {
            $scoped = $this->authorizer->scope(
                [
                    'after_type_key' => $query->afterTypeKey?->value,
                    'limit' => $query->limit,
                ],
                $actor,
                'content.type.list',
                'content.type',
            );
            $afterTypeKey = $scoped['after_type_key'] ?? null;
            $limit = $scoped['limit'] ?? null;

            if (
                ($afterTypeKey !== null && !is_string($afterTypeKey))
                || !is_int($limit)
                || $limit !== $query->limit
            ) {
                throw new ContentRejected(
                    'content_query_scope_invalid',
                    'Access returned an invalid Content type query scope.',
                );
            }

            $this->input->assertPageLimit($limit);
            $this->repository->assertCompleteCompatible();
            $rows = $this->repository->typeRows($afterTypeKey, $limit);
            $items = array_map(
                fn (array $row): ContentType => $this->hydrateType($row),
                $rows,
            );
            $last = end($items);
            $next = count($items) === $limit && $last instanceof ContentType
                ? $last->typeKey
                : null;

            return new ContentTypePage($items, $next);
        } catch (ContentRejected $exception) {
            $this->auditDomainDenial(
                $connection,
                $actor,
                'content.type.list',
                $exception,
                'content:type:list',
            );

            throw $exception;
        }
    }

    /**
     * @param list<string> $storageOperations
     */
    private function preflightProtected(
        ActorContext $actor,
        string $operation,
        array $storageOperations = [],
    ): ConnectionInterface {
        $connection = $this->participants->assertSharedConnection();
        $this->input->assertActor($actor);
        $this->authorizer->assertAllowed($actor, $operation, $storageOperations);

        return $connection;
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateType(array $row): ContentType
    {
        return new ContentType(
            new ContentTypeKey((string) $row['type_key']),
            (int) $row['current_version'],
        );
    }

    /**
     * @param array<string, bool|int|string|null> $row
     */
    private function hydrateTypeVersion(array $row): ContentTypeVersion
    {
        $typeKey = new ContentTypeKey((string) $row['type_key']);
        $storageRef = new StorageSchemaVersionRef(
            (string) $row['storage_schema_ref'],
            (int) $row['storage_schema_version'],
        );

        try {
            $schema = $this->storage->schemaVersion($storageRef);
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'storage',
                'schema_version_read_failed',
                $exception,
            );
        }

        $fields = $this->schemas->fieldDefinitions($schema);
        $projection = ContentProjectionContract::fromArray(
            $this->decodeObject((string) $row['projection_contract']),
            $fields,
        );
        $safeMetadata = $this->decodeObject((string) $row['safe_metadata']);

        return new ContentTypeVersion(
            typeKey: $typeKey,
            version: (int) $row['version'],
            storageSchemaRef: $storageRef->schemaId,
            storageSchemaVersion: $storageRef->version,
            schemaHash: (string) $row['schema_hash'],
            fieldDefinitions: $fields,
            projectionContract: $projection,
            safeMetadata: $safeMetadata,
            createdBy: (string) $row['created_by'],
            correlationId: (string) $row['correlation_id'],
            createdAt: $this->dateTime((string) $row['created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_json_invalid',
                $exception,
            );
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_json_invalid',
            );
        }

        return $decoded;
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s.u',
                $value,
                new DateTimeZone('UTC'),
            );
            $errors = DateTimeImmutable::getLastErrors();

            if (
                !$dateTime instanceof DateTimeImmutable
                || (
                    $errors !== false
                    && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)
                )
                || $dateTime->format('Y-m-d H:i:s.u') !== $value
            ) {
                throw new \UnexpectedValueException(
                    'Persisted Content timestamps must use exact UTC microseconds.',
                );
            }

            return $dateTime;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'content',
                'persisted_timestamp_invalid',
                $exception,
            );
        }
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function typeSubject(ContentTypeKey $typeKey): string
    {
        return 'content:type:'.$typeKey->value;
    }

    /**
     * @param array<string, bool|int|string|null> $context
     */
    private function auditDomainDenial(
        ConnectionInterface $connection,
        ActorContext $actor,
        string $operation,
        ContentRejected $rejection,
        string $subject,
        array $context = [],
    ): void {
        try {
            $connection->transaction(function () use (
                $actor,
                $operation,
                $rejection,
                $subject,
                $context,
            ): void {
                $this->audit->domainDenied(
                    $actor,
                    $operation,
                    $rejection->reasonCode(),
                    $subject,
                    $context,
                );
            }, 1);
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'audit',
                'content_audit_write_failed',
                $exception,
            );
        }
    }
}
