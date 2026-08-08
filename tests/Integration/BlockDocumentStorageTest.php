<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Content\Access\AccessBlockDocumentAuthorization;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Runtime\BlockDocumentNormalizer;
use Larena\Content\Services\StorageBackedBlockDocumentService;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Storage\Exceptions\StorageRejected;

final class BlockDocumentStorageTest extends TestCase
{
    public function test_document_is_migrated_sanitized_versioned_and_projected_deterministically(): void
    {
        $runtime = ContentRuntimeHarness::create();
        try {
            $runtime->insertFile('550e8400-e29b-41d4-a716-446655440000');
            $service = $runtime->blockDocuments;
            $created = $service->create($this->document(), $runtime->admin->actorRef);
            self::assertSame(1, $created->storageRef->revision);
            self::assertSame('Hello world', $created->document['blocks'][0]['data']['text']);
            self::assertSame(1, $created->document['blocks'][0]['data_version']);
            self::assertSame($created->semanticHash, $service->project('page.home', 'scope:global', $runtime->admin->actorRef)['semantic_hash']);
            self::assertStringNotContainsString('<b>', json_encode($service->project('page.home', 'scope:global', $runtime->admin->actorRef), JSON_THROW_ON_ERROR));

            $updatedDocument = $this->document();
            $updatedDocument['blocks'][0]['data']['body'] = 'Updated';
            $updated = $service->update($updatedDocument, $created->storageRef, $runtime->admin->actorRef);
            self::assertSame(2, $updated->storageRef->revision);
            self::assertSame('Updated', $updated->document['blocks'][0]['data']['text']);

            try {
                $service->update($this->document(), $created->storageRef, $runtime->admin->actorRef);
                self::fail('A stale block-document write was accepted.');
            } catch (StorageRejected $exception) {
                self::assertSame('storage_record_revision_conflict', $exception->reasonCode);
            }
            self::assertSame(2, $service->read('page.home', 'scope:global', $runtime->admin->actorRef)?->storageRef->revision);
        } finally {
            $runtime->close();
        }
    }

    public function test_object_key_order_is_semantically_stable_but_block_order_is_not(): void
    {
        $normalizer = new BlockDocumentNormalizer();
        $document = $this->document();
        $rekeyed = [
            'version' => $document['version'],
            'time' => $document['time'],
            'schema_version' => $document['schema_version'],
            'schema' => $document['schema'],
            'registry' => $document['registry'],
            'document_id' => $document['document_id'],
            'scope_ref' => $document['scope_ref'],
            'blocks' => array_map(static fn (array $block): array => [
                'type' => $block['type'],
                'id' => $block['id'],
                'data_version' => $block['data_version'],
                'data' => array_reverse($block['data'], true),
            ], $document['blocks']),
        ];
        self::assertSame($normalizer->semanticHash($document), $normalizer->semanticHash($rekeyed));
        $reordered = $document;
        $reordered['blocks'] = array_reverse($reordered['blocks']);
        self::assertNotSame($normalizer->semanticHash($document), $normalizer->semanticHash($reordered));
        self::assertSame(
            $normalizer->normalize($document),
            $normalizer->normalize($normalizer->normalize($document)),
        );
    }

    public function test_unknown_duplicate_unsafe_deep_and_oversized_documents_fail_without_partial_mutation(): void
    {
        $runtime = ContentRuntimeHarness::create();
        try {
            $service = $runtime->blockDocuments;
            $cases = [];
            $unknown = $this->document();
            $unknown['blocks'][0]['type'] = 'unknown';
            $cases[] = $unknown;
            $duplicate = $this->document();
            $duplicate['blocks'][1]['id'] = $duplicate['blocks'][0]['id'];
            $cases[] = $duplicate;
            $unsafe = $this->document();
            $unsafe['blocks'][0]['data']['body'] = '<script>private()</script>';
            $cases[] = $unsafe;
            $oversized = $this->document();
            $oversized['blocks'][0]['data']['body'] = str_repeat('x', 10_001);
            $cases[] = $oversized;
            $deep = $this->document();
            $deep['unexpected'] = [[[[[[[[['too_deep']]]]]]]]];
            $cases[] = $deep;

            foreach ($cases as $case) {
                try {
                    $service->create($case, $runtime->admin->actorRef);
                    self::fail('Invalid block document was accepted.');
                } catch (ContentRejected $exception) {
                    self::assertStringNotContainsString('private', $exception->getMessage());
                }
            }
            self::assertSame(0, (int) $runtime->connection->table('larena_storage_records')->count());
        } finally {
            $runtime->close();
        }
    }

    public function test_unauthorized_actor_cannot_read_or_create_document(): void
    {
        $runtime = ContentRuntimeHarness::create();
        try {
            $runtime->insertFile('550e8400-e29b-41d4-a716-446655440000');
            $service = $runtime->blockDocuments;
            $created = $service->create($this->document(), $runtime->admin->actorRef);
            foreach ([
                fn () => $service->read('page.home', 'scope:global', 'user:admin_identity:999'),
                fn () => $service->create(array_replace($this->document(), ['document_id' => 'page.denied']), 'user:admin_identity:999'),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Unauthorized block-document operation was accepted.');
                } catch (StorageRejected|AccessMutationRejected) {
                }
            }
            self::assertSame($created->storageRef->key(), $service->read('page.home', 'scope:global', $runtime->admin->actorRef)?->storageRef->key());
            self::assertSame(1, (int) $runtime->connection->table('larena_storage_records')->count());
        } finally {
            $runtime->close();
        }
    }

    public function test_real_access_identities_are_isolated_by_actor_and_requested_scope(): void
    {
        $runtime = ContentRuntimeHarness::create();
        try {
            $runtime->insertFile('550e8400-e29b-41d4-a716-446655440000');
            $scopes = new BlockDocumentScopeFixture(
                [
                    $runtime->admin->actorRef => 'scope:tenant-alpha',
                    $runtime->reader->actorRef => 'scope:tenant-beta',
                    $runtime->editor->actorRef => 'scope:tenant-beta',
                ],
                ['550e8400-e29b-41d4-a716-446655440000' => 'scope:tenant-alpha'],
            );
            $service = new StorageBackedBlockDocumentService(
                $runtime->ownerStorage,
                new AccessBlockDocumentAuthorization($runtime->ownerAuthorizer, $scopes),
                $runtime->logicalFiles,
            );
            $alphaDocument = $this->document();
            $alphaDocument['scope_ref'] = 'scope:tenant-alpha';
            $alpha = $service->create($alphaDocument, $runtime->admin->actorRef);

            $tenant = $this->document();
            $tenant['scope_ref'] = 'scope:tenant-beta';
            $tenant['document_id'] = 'page.beta';
            $tenant['blocks'] = [$tenant['blocks'][0]];
            $tenant['blocks'][0]['data']['body'] = 'Tenant beta';
            $beta = $service->create($tenant, $runtime->editor->actorRef);

            self::assertNotSame($alpha->storageRef->recordId, $beta->storageRef->recordId);
            self::assertSame($alpha->semanticHash, $service->project('page.home', 'scope:tenant-alpha', $runtime->admin->actorRef)['semantic_hash']);
            self::assertSame(1, $service->read('page.beta', 'scope:tenant-beta', $runtime->editor->actorRef)?->storageRef->revision);

            foreach ([
                fn () => $service->read('page.home', 'scope:tenant-alpha', $runtime->reader->actorRef),
                fn () => $service->project('page.home', 'scope:tenant-alpha', $runtime->reader->actorRef),
                fn () => $service->update($alphaDocument, $alpha->storageRef, $runtime->editor->actorRef),
                fn () => $service->create(array_replace($alphaDocument, ['document_id' => 'page.foreign']), $runtime->editor->actorRef),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('A foreign-scope Content operation was accepted.');
                } catch (ContentRejected $exception) {
                    self::assertSame('content_document_scope_denied', $exception->reasonCode());
                    self::assertNull($exception->getPrevious());
                }
            }
            self::assertSame(1, $service->read('page.home', 'scope:tenant-alpha', $runtime->admin->actorRef)?->storageRef->revision);
            self::assertSame(2, (int) $runtime->connection->table('larena_storage_records')->count());
        } finally {
            $runtime->close();
        }
    }

    public function test_missing_deleted_ineligible_and_stale_scope_files_fail_closed(): void
    {
        $runtime = ContentRuntimeHarness::create();
        try {
            $file = '550e8400-e29b-41d4-a716-446655440000';
            $runtime->insertFile($file);
            $scopes = new BlockDocumentScopeFixture(
                [$runtime->admin->actorRef => 'scope:tenant-alpha'],
                [$file => 'scope:tenant-alpha'],
            );
            $service = new StorageBackedBlockDocumentService(
                $runtime->ownerStorage,
                new AccessBlockDocumentAuthorization($runtime->ownerAuthorizer, $scopes),
                $runtime->logicalFiles,
            );
            $document = $this->document();
            $document['scope_ref'] = 'scope:tenant-alpha';
            $created = $service->create($document, $runtime->admin->actorRef);
            self::assertSame($created->semanticHash, $service->project('page.home', 'scope:tenant-alpha', $runtime->admin->actorRef)['semantic_hash']);

            $runtime->connection->table('larena_files')->where('logical_ref', $file)->update(['deleted_at' => '2026-08-08 00:00:00']);
            $this->assertFileRejected(fn () => $service->project('page.home', 'scope:tenant-alpha', $runtime->admin->actorRef));
            $deletedCreate = $document;
            $deletedCreate['document_id'] = 'page.deleted';
            $this->assertFileRejected(fn () => $service->create($deletedCreate, $runtime->admin->actorRef));
            $this->assertFileRejected(fn () => $service->update($document, $created->storageRef, $runtime->admin->actorRef));
            $runtime->connection->table('larena_files')->where('logical_ref', $file)->update(['deleted_at' => null, 'lifecycle_class' => 'temporary']);
            $this->assertFileRejected(fn () => $service->project('page.home', 'scope:tenant-alpha', $runtime->admin->actorRef));
            $ineligibleCreate = $document;
            $ineligibleCreate['document_id'] = 'page.ineligible';
            $this->assertFileRejected(fn () => $service->create($ineligibleCreate, $runtime->admin->actorRef));
            $this->assertFileRejected(fn () => $service->update($document, $created->storageRef, $runtime->admin->actorRef));
            $runtime->connection->table('larena_files')->where('logical_ref', $file)->update(['lifecycle_class' => 'persistent']);
            $scopes->fileScopes[$file] = 'scope:tenant-beta';
            $this->assertScopeRejected(fn () => $service->project('page.home', 'scope:tenant-alpha', $runtime->admin->actorRef));

            $missing = $document;
            $missing['document_id'] = 'page.missing';
            $missing['blocks'][1]['data']['file_ref'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            $scopes->fileScopes['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'] = 'scope:tenant-alpha';
            $this->assertFileRejected(fn () => $service->create($missing, $runtime->admin->actorRef));
            $missingUpdate = $document;
            $missingUpdate['blocks'][1]['data']['file_ref'] = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
            $beforeUpdate = [
                (int) $runtime->connection->table('larena_storage_records')->count(),
                (int) $runtime->connection->table('larena_storage_record_versions')->count(),
            ];
            $this->assertFileRejected(fn () => $service->update($missingUpdate, $created->storageRef, $runtime->admin->actorRef));
            self::assertSame($beforeUpdate, [
                (int) $runtime->connection->table('larena_storage_records')->count(),
                (int) $runtime->connection->table('larena_storage_record_versions')->count(),
            ]);
            self::assertSame(1, (int) $runtime->connection->table('larena_storage_records')->count());
        } finally {
            $runtime->close();
        }
    }

    private function assertFileRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('An unavailable logical file was accepted.');
        } catch (ContentRejected $exception) {
            self::assertSame('content_block_file_unavailable', $exception->reasonCode());
            self::assertNull($exception->getPrevious());
        }
    }

    private function assertScopeRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('A stale-scope logical file was accepted.');
        } catch (ContentRejected $exception) {
            self::assertSame('content_document_scope_denied', $exception->reasonCode());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'schema' => 'larena.content.block_document',
            'schema_version' => 0,
            'document_id' => 'page.home',
            'scope_ref' => 'scope:global',
            'registry' => 'larena.content.blocks',
            'version' => '2.28.2',
            'time' => 1_786_147_200_000,
            'blocks' => [
                ['id' => 'paragraph_1', 'type' => 'paragraph', 'data_version' => 0, 'data' => ['body' => 'Hello <b>world</b>']],
                ['id' => 'image_1', 'type' => 'image', 'data_version' => 0, 'data' => ['file_ref' => '550e8400-e29b-41d4-a716-446655440000', 'caption' => 'Image', 'alt' => 'Safe']],
            ],
        ];
    }
}

final class BlockDocumentScopeFixture implements QueryScopeProvider
{
    /**
     * @param array<string, string> $actorScopes
     * @param array<string, string> $fileScopes
     */
    public function __construct(
        public array $actorScopes,
        public array $fileScopes,
    ) {
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return $resourceType === 'content.item' && $operation === 'content.item.list';
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        $scope = $this->actorScopes[$actor] ?? 'scope:denied';
        $result = ['resource_type' => 'content.item', 'scope_ref' => $scope];
        if (isset($query['logical_file_ref'])) {
            $file = (string) $query['logical_file_ref'];
            $result = [
                'resource_type' => 'content.item',
                'scope_ref' => $scope,
                'logical_file_ref' => $file,
                'logical_file_authorized' => ($this->fileScopes[$file] ?? null) === $scope,
            ];
        }

        return $result;
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        return AccessDecision::deny($operation, $actor, $resourceType.':all', 'fixture_scoped');
    }
}
