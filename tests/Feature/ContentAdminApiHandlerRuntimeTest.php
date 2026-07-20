<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Auth\Enums\EntryObjectType;
use Larena\Auth\ValueObjects\EntryObject;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Rest\ContentAdminApiOperationHandler;
use Larena\Content\Rest\ContentAdminReadModel;
use Larena\Content\Rest\ContentAdminValueCodec;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Rest\Contracts\ApiOperationDescriptor;
use Larena\Rest\Contracts\OperationContextMetadata;
use Larena\Rest\Exceptions\ApiOperationException;
use Larena\Rest\Registry\MutableOperationHandlerRegistry;
use Larena\Rest\Registry\PackageApiContractLoader;
use Larena\Rest\Runtime\RequestSchemaValidator;

final class ContentAdminApiHandlerRuntimeTest extends TestCase
{
    private ContentRuntimeHarness $runtime;

    /** @var array<string, ApiOperationDescriptor> */
    private array $operations;

    private MutableOperationHandlerRegistry $handlers;

    private RequestSchemaValidator $schemas;

    private OperationContext $context;

    private int $requestSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = ContentRuntimeHarness::create();
        $contract = (new PackageApiContractLoader())->loadFile(
            dirname(__DIR__, 2).'/api.yaml',
            'larena/content',
        );
        $this->operations = [];
        foreach ($contract->operations as $operation) {
            $this->operations[$operation->operationKey] = $operation;
        }
        $codec = new ContentAdminValueCodec();
        $handler = new ContentAdminApiOperationHandler(
            $this->runtime->types,
            $this->runtime->items,
            new ContentAdminReadModel(
                $this->runtime->types,
                $this->runtime->items,
                $this->runtime->storage,
                $codec,
            ),
            $codec,
        );
        $this->handlers = new MutableOperationHandlerRegistry();
        ContentAdminApiOperationHandler::registerLazy(
            $this->handlers,
            static fn (): ContentAdminApiOperationHandler => $handler,
        );
        $this->schemas = new RequestSchemaValidator();
        $entry = EntryObject::create(
            EntryObjectType::User,
            $this->runtime->admin->actorRef,
            'admin_session',
            'password',
        );
        $this->context = new OperationContext(
            $this->runtime->admin->actorRef,
            'content-admin-api-runtime',
            metadata: [OperationContextMetadata::AUTHENTICATED_ACTOR => $entry],
        );
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        parent::tearDown();
    }

    public function test_all_twenty_handlers_round_trip_through_their_compiled_schemas(): void
    {
        $typeBody = $this->typeBody();
        $createdType = $this->call('content.type_admin.create', [], [], $typeBody);
        self::assertSame(1, $createdType['type']['current_version']);
        $this->call('content.type_admin.list');
        $this->call('content.type_admin.read', ['type_key' => 'article']);
        $this->call('content.type_admin.versions.list', ['type_key' => 'article']);
        $this->call(
            'content.type_admin.versions.read',
            ['type_key' => 'article', 'version' => 1],
        );

        $versionBody = $this->versionBody();
        $preview = $this->call(
            'content.type_admin.versions.preview',
            ['type_key' => 'article'],
            [],
            $versionBody,
        );
        self::assertTrue($preview['compatibility']['compatible']);
        $createdVersion = $this->call(
            'content.type_admin.versions.create',
            ['type_key' => 'article'],
            [],
            $versionBody,
        );
        self::assertSame(
            ['type_key' => 'article', 'current_version' => 2],
            $createdVersion['type'],
        );
        self::assertArrayNotHasKey('version', $createdVersion);

        $created = $this->call('content.item_admin.create', [], [], [
            'type_key' => 'article',
            'locale' => 'en',
            'slug' => 'api-article',
            'visibility' => 'public',
            'values' => $this->values('API article', 'First API body', 'First summary'),
        ]);
        $itemRef = $created['item']['item_ref'];
        self::assertIsString($itemRef);
        $this->call('content.item_admin.list');
        $read = $this->call('content.item_admin.read', ['item_ref' => $itemRef]);
        self::assertSame('API article', $read['item']['revision']['values'][0]['value']);

        $updated = $this->call(
            'content.item_admin.update',
            ['item_ref' => $itemRef],
            [],
            [
                'expected_revision' => 1,
                'slug' => 'api-article',
                'visibility' => 'public',
                'values' => $this->values('Updated API article', 'Updated API body', 'Updated summary'),
            ],
        );
        self::assertSame(2, $updated['item']['current_revision']);
        $this->call(
            'content.item_admin.revisions.list',
            ['item_ref' => $itemRef],
        );
        $this->call(
            'content.item_admin.revisions.read',
            ['item_ref' => $itemRef, 'revision' => 2],
        );
        $restored = $this->call(
            'content.item_admin.revisions.restore',
            ['item_ref' => $itemRef, 'revision' => 1],
            [],
            ['expected_revision' => 2],
        );
        self::assertSame(3, $restored['item']['current_revision']);

        $this->runtime->insertFile(ContentRuntimeHarness::PUBLIC_FILE);
        $this->runtime->insertFile(ContentRuntimeHarness::SECOND_PUBLIC_FILE);
        $this->call(
            'content.item_admin.attachments.list',
            ['item_ref' => $itemRef],
        );
        $firstAttach = $this->call(
            'content.item_admin.attachments.attach',
            ['item_ref' => $itemRef],
            [],
            [
                'expected_revision' => 3,
                'logical_file_ref' => ContentRuntimeHarness::PUBLIC_FILE,
                'role' => 'hero',
            ],
        );
        self::assertSame(4, $firstAttach['item']['current_revision']);
        $this->call(
            'content.item_admin.attachments.attach',
            ['item_ref' => $itemRef],
            [],
            [
                'expected_revision' => 4,
                'logical_file_ref' => ContentRuntimeHarness::SECOND_PUBLIC_FILE,
                'role' => 'gallery',
            ],
        );
        $this->call(
            'content.item_admin.attachments.reorder',
            ['item_ref' => $itemRef],
            [],
            [
                'expected_revision' => 5,
                'attachments' => [
                    [
                        'logical_file_ref' => ContentRuntimeHarness::SECOND_PUBLIC_FILE,
                        'role' => 'gallery',
                        'position' => 0,
                    ],
                    [
                        'logical_file_ref' => ContentRuntimeHarness::PUBLIC_FILE,
                        'role' => 'hero',
                        'position' => 1,
                    ],
                ],
            ],
        );
        $this->call(
            'content.item_admin.attachments.detach',
            [
                'item_ref' => $itemRef,
                'logical_file_ref' => ContentRuntimeHarness::PUBLIC_FILE,
            ],
            [],
            ['expected_revision' => 6, 'role' => 'hero'],
        );

        $published = $this->call(
            'content.item_admin.publish',
            ['item_ref' => $itemRef],
            [],
            ['expected_revision' => 7],
        );
        self::assertSame(8, $published['item']['current_revision']);
        $unpublished = $this->call(
            'content.item_admin.unpublish',
            ['item_ref' => $itemRef],
            [],
            ['expected_revision' => 8],
        );
        self::assertSame(9, $unpublished['item']['current_revision']);
        self::assertSame(20, count($this->operations));
    }

    public function test_duplicate_type_is_a_409_conflict_through_the_compiled_handler(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());

        $failure = $this->callFailure(
            'content.type_admin.create',
            [],
            [],
            $this->typeBody(),
        );

        self::assertSame('type_already_exists', $failure->errorCode);
        self::assertSame(409, $failure->httpStatus);
        self::assertSame(
            'The Content operation conflicts with the current resource state.',
            $failure->safeMessage,
        );
    }

    public function test_same_field_type_version_candidates_are_409_conflicts(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());
        $body = [
            'expected_version' => 1,
            'fields' => $this->fields(false),
            'projection' => [
                'version' => 1,
                'title_field' => 'title',
                'snippet_field' => 'title',
                'searchable_fields' => ['title', 'featured'],
            ],
            'safe_metadata' => ['label' => 'Article v2 metadata only'],
        ];

        foreach (
            [
                'content.type_admin.versions.preview',
                'content.type_admin.versions.create',
            ] as $operation
        ) {
            $failure = $this->callFailure(
                $operation,
                ['type_key' => 'article'],
                [],
                $body,
            );
            self::assertSame('type_version_no_change', $failure->errorCode);
            self::assertSame(409, $failure->httpStatus);
            self::assertSame(
                'The Content operation conflicts with the current resource state.',
                $failure->safeMessage,
            );
        }
    }

    public function test_item_detail_fails_closed_when_head_and_current_revision_drift(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());
        $created = $this->call('content.item_admin.create', [], [], [
            'type_key' => 'article',
            'locale' => 'en',
            'slug' => 'head-proof',
            'visibility' => 'public',
            'values' => array_slice(
                $this->values(
                    'head-proof-private-canary',
                    'head-proof-body-canary',
                    'unused',
                ),
                0,
                4,
            ),
        ]);
        $itemRef = $created['item']['item_ref'];
        self::assertIsString($itemRef);
        $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $itemRef)
            ->where('revision', 1)
            ->update(['slug' => 'corrupted-head-proof']);

        $failure = $this->callRuntimeFailure(
            'content.item_admin.read',
            ['item_ref' => $itemRef],
        );

        $this->assertSanitizedIntegrationFailure(
            $failure,
            'content_admin_item_head_revision_mismatch',
            ['head-proof-private-canary', 'head-proof-body-canary'],
        );
    }

    public function test_item_and_revision_details_fail_closed_before_values_on_type_schema_drift(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());
        $created = $this->call('content.item_admin.create', [], [], [
            'type_key' => 'article',
            'locale' => 'en',
            'slug' => 'schema-proof',
            'visibility' => 'public',
            'values' => array_slice(
                $this->values(
                    'schema-proof-private-canary',
                    'schema-proof-body-canary',
                    'unused',
                ),
                0,
                4,
            ),
        ]);
        $itemRef = $created['item']['item_ref'];
        self::assertIsString($itemRef);
        $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $itemRef)
            ->where('revision', 1)
            ->update(['storage_schema_version' => 2]);

        foreach (
            [
                ['content.item_admin.read', ['item_ref' => $itemRef]],
                [
                    'content.item_admin.revisions.read',
                    ['item_ref' => $itemRef, 'revision' => 1],
                ],
            ] as [$operation, $path]
        ) {
            $failure = $this->callRuntimeFailure($operation, $path);
            $this->assertSanitizedIntegrationFailure(
                $failure,
                'content_admin_revision_type_schema_mismatch',
                ['schema-proof-private-canary', 'schema-proof-body-canary'],
            );
        }
    }

    public function test_revision_detail_fails_closed_when_storage_owner_does_not_match(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());
        $created = $this->call('content.item_admin.create', [], [], [
            'type_key' => 'article',
            'locale' => 'en',
            'slug' => 'storage-proof',
            'visibility' => 'public',
            'values' => array_slice(
                $this->values(
                    'storage-proof-private-canary',
                    'storage-proof-body-canary',
                    'unused',
                ),
                0,
                4,
            ),
        ]);
        $itemRef = $created['item']['item_ref'];
        self::assertIsString($itemRef);
        $revision = $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $itemRef)
            ->where('revision', 1)
            ->first();
        self::assertNotNull($revision);
        $this->runtime->connection
            ->table('larena_storage_record_versions')
            ->where('schema_id', (string) $revision->storage_schema_ref)
            ->where('record_id', (string) $revision->storage_record_ref)
            ->where('revision', (int) $revision->storage_record_version)
            ->update(['owner_ref' => 'content:item:corrupted-owner']);

        $failure = $this->callRuntimeFailure(
            'content.item_admin.revisions.read',
            ['item_ref' => $itemRef, 'revision' => 1],
        );
        $this->assertSanitizedIntegrationFailure(
            $failure,
            'content_admin_revision_storage_mismatch',
            ['storage-proof-private-canary', 'storage-proof-body-canary'],
        );
    }

    public function test_type_reads_fail_closed_when_content_and_storage_schema_hashes_drift(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());
        $this->runtime->connection
            ->table('larena_content_type_versions')
            ->where('type_key', 'article')
            ->where('version', 1)
            ->update(['schema_hash' => str_repeat('f', 64)]);

        foreach (
            [
                ['content.type_admin.read', ['type_key' => 'article']],
                [
                    'content.type_admin.versions.read',
                    ['type_key' => 'article', 'version' => 1],
                ],
            ] as [$operation, $path]
        ) {
            $failure = $this->callRuntimeFailure($operation, $path);
            $this->assertSanitizedIntegrationFailure(
                $failure,
                'type_version_storage_hash_mismatch',
                ['Article', str_repeat('f', 64)],
            );
        }
    }

    public function test_type_reads_fail_closed_when_persisted_projection_is_semantically_corrupt(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());
        $projectionCanary = 'projection-private-canary';
        $this->runtime->connection
            ->table('larena_content_type_versions')
            ->where('type_key', 'article')
            ->where('version', 1)
            ->update([
                'projection_contract' => json_encode([
                    'version' => 1,
                    'title_field' => $projectionCanary,
                    'snippet_field' => 'body',
                    'searchable_fields' => ['title', 'body', 'featured'],
                ], JSON_THROW_ON_ERROR),
            ]);

        foreach (
            [
                ['content.type_admin.read', ['type_key' => 'article']],
                [
                    'content.type_admin.versions.read',
                    ['type_key' => 'article', 'version' => 1],
                ],
            ] as [$operation, $path]
        ) {
            $failure = $this->callRuntimeFailure($operation, $path);
            $this->assertSanitizedIntegrationFailure(
                $failure,
                'persisted_type_version_invalid',
                [$projectionCanary],
            );
        }
    }

    public function test_item_and_revision_reads_fail_closed_for_invalid_persisted_enums(): void
    {
        $this->call('content.type_admin.create', [], [], $this->typeBody());
        $created = $this->call('content.item_admin.create', [], [], [
            'type_key' => 'article',
            'locale' => 'en',
            'slug' => 'enum-proof',
            'visibility' => 'public',
            'values' => array_slice(
                $this->values(
                    'enum-proof-private-canary',
                    'enum-proof-body-canary',
                    'unused',
                ),
                0,
                4,
            ),
        ]);
        $itemRef = $created['item']['item_ref'];
        self::assertIsString($itemRef);

        $itemEnumCanary = 'persisted-item-status-private-canary';
        $this->runtime->connection
            ->table('larena_content_items')
            ->where('item_ref', $itemRef)
            ->update(['current_status' => $itemEnumCanary]);
        $itemFailure = $this->callRuntimeFailure(
            'content.item_admin.read',
            ['item_ref' => $itemRef],
        );
        $this->assertSanitizedIntegrationFailure(
            $itemFailure,
            'persisted_item_invalid',
            [$itemEnumCanary, 'enum-proof-private-canary'],
        );

        $this->runtime->connection
            ->table('larena_content_items')
            ->where('item_ref', $itemRef)
            ->update(['current_status' => 'draft']);
        $revisionEnumCanary = 'persisted-revision-status-private-canary';
        $this->runtime->connection
            ->table('larena_content_item_revisions')
            ->where('item_ref', $itemRef)
            ->where('revision', 1)
            ->update(['status' => $revisionEnumCanary]);
        $revisionFailure = $this->callRuntimeFailure(
            'content.item_admin.revisions.read',
            ['item_ref' => $itemRef, 'revision' => 1],
        );
        $this->assertSanitizedIntegrationFailure(
            $revisionFailure,
            'persisted_revision_invalid',
            [$revisionEnumCanary, 'enum-proof-private-canary'],
        );
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(
        string $operationKey,
        array $path = [],
        array $query = [],
        array $body = [],
    ): array {
        $operation = $this->operations[$operationKey];
        $this->requestSequence++;
        $validation = $this->schemas->validate(
            $operation,
            ['path' => $path, 'query' => $query, 'body' => $body],
            $operation->idempotencyRequired
                ? sprintf('content-api-request-%04d', $this->requestSequence)
                : null,
        );
        self::assertSame([], $validation->errors, $operationKey.' request schema failed.');
        $handler = $this->handlers->get($operation->handlerReference);
        self::assertIsCallable($handler);
        $result = $handler(
            $validation->input,
            new OperationDescriptor(
                $operationKey,
                OperationExecutionMode::Sync,
            ),
            $this->context,
        );
        $response = $this->schemas->validateResponse($operation, $result);
        self::assertSame([], $response->errors, $operationKey.' response schema failed.');

        return $response->payload;
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function callFailure(
        string $operationKey,
        array $path = [],
        array $query = [],
        array $body = [],
    ): ApiOperationException {
        $operation = $this->operations[$operationKey];
        $this->requestSequence++;
        $validation = $this->schemas->validate(
            $operation,
            ['path' => $path, 'query' => $query, 'body' => $body],
            $operation->idempotencyRequired
                ? sprintf('content-api-failure-%04d', $this->requestSequence)
                : null,
        );
        self::assertSame([], $validation->errors, $operationKey.' request schema failed.');
        $handler = $this->handlers->get($operation->handlerReference);
        self::assertIsCallable($handler);

        try {
            $handler(
                $validation->input,
                new OperationDescriptor(
                    $operationKey,
                    OperationExecutionMode::Sync,
                ),
                $this->context,
            );
        } catch (ApiOperationException $exception) {
            return $exception;
        }

        self::fail("{$operationKey} did not return the expected API failure.");
    }

    /**
     * @param array<string, mixed> $path
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function callRuntimeFailure(
        string $operationKey,
        array $path = [],
        array $query = [],
        array $body = [],
    ): \RuntimeException {
        $operation = $this->operations[$operationKey];
        $this->requestSequence++;
        $validation = $this->schemas->validate(
            $operation,
            ['path' => $path, 'query' => $query, 'body' => $body],
            $operation->idempotencyRequired
                ? sprintf('content-api-runtime-failure-%04d', $this->requestSequence)
                : null,
        );
        self::assertSame([], $validation->errors, $operationKey.' request schema failed.');
        $handler = $this->handlers->get($operation->handlerReference);
        self::assertIsCallable($handler);

        try {
            $handler(
                $validation->input,
                new OperationDescriptor(
                    $operationKey,
                    OperationExecutionMode::Sync,
                ),
                $this->context,
            );
        } catch (ApiOperationException $exception) {
            self::fail(
                "{$operationKey} exposed integration drift as {$exception->httpStatus}.",
            );
        } catch (\RuntimeException $exception) {
            return $exception;
        }

        self::fail("{$operationKey} did not fail closed for integration drift.");
    }

    /** @param list<string> $privateCanaries */
    private function assertSanitizedIntegrationFailure(
        \RuntimeException $failure,
        string $reasonCode,
        array $privateCanaries,
    ): void {
        self::assertSame('content_admin_integration_failed', $failure->getMessage());
        $previous = $failure->getPrevious();
        self::assertInstanceOf(ContentIntegrationFailed::class, $previous);
        self::assertSame($reasonCode, $previous->reasonCode);

        $diagnostic = $failure->getMessage().' '.$previous->getMessage();
        foreach ($privateCanaries as $privateCanary) {
            self::assertStringNotContainsString($privateCanary, $diagnostic);
        }
    }

    /** @return array<string, mixed> */
    private function typeBody(): array
    {
        return [
            'type_key' => 'article',
            'fields' => $this->fields(false),
            'projection' => [
                'version' => 1,
                'title_field' => 'title',
                'snippet_field' => 'body',
                'searchable_fields' => ['title', 'body', 'featured'],
            ],
            'safe_metadata' => ['label' => 'Article'],
        ];
    }

    /** @return array<string, mixed> */
    private function versionBody(): array
    {
        return [
            'expected_version' => 1,
            'fields' => $this->fields(true),
            'projection' => [
                'version' => 1,
                'title_field' => 'title',
                'snippet_field' => 'body',
                'searchable_fields' => ['title', 'body', 'featured', 'summary'],
            ],
            'safe_metadata' => ['label' => 'Article'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fields(bool $withSummary): array
    {
        $fields = [
            ['key' => 'title', 'property_type' => 'string', 'visibility' => 'public', 'required' => true, 'constraints' => []],
            ['key' => 'body', 'property_type' => 'string', 'visibility' => 'public', 'required' => true, 'constraints' => []],
            ['key' => 'featured', 'property_type' => 'boolean', 'visibility' => 'public', 'required' => false, 'constraints' => []],
            ['key' => 'internal_notes', 'property_type' => 'string', 'visibility' => 'private', 'required' => false, 'constraints' => []],
        ];
        if ($withSummary) {
            $fields[] = [
                'key' => 'summary',
                'property_type' => 'string',
                'visibility' => 'public',
                'required' => false,
                'constraints' => [],
            ];
        }

        return $fields;
    }

    /** @return list<array{key:string,value:string|bool}> */
    private function values(string $title, string $body, string $summary): array
    {
        return [
            ['key' => 'title', 'value' => $title],
            ['key' => 'body', 'value' => $body],
            ['key' => 'featured', 'value' => true],
            ['key' => 'internal_notes', 'value' => 'private'],
            ['key' => 'summary', 'value' => $summary],
        ];
    }
}
