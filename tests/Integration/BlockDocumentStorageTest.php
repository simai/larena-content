<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Access\Exceptions\AccessMutationRejected;
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
            $service = new StorageBackedBlockDocumentService($runtime->ownerStorage);
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
            $service = new StorageBackedBlockDocumentService($runtime->ownerStorage);
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
            $service = new StorageBackedBlockDocumentService($runtime->ownerStorage);
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

    public function test_same_document_id_is_isolated_by_explicit_scope(): void
    {
        $runtime = ContentRuntimeHarness::create();
        try {
            $service = new StorageBackedBlockDocumentService($runtime->ownerStorage);
            $global = $service->create($this->document(), $runtime->admin->actorRef);
            self::assertNull($service->read('page.home', 'scope:tenant-beta', $runtime->admin->actorRef));

            $tenant = $this->document();
            $tenant['scope_ref'] = 'scope:tenant-beta';
            $tenant['blocks'][0]['data']['body'] = 'Tenant beta';
            $beta = $service->create($tenant, $runtime->admin->actorRef);
            $betaService = new StorageBackedBlockDocumentService($runtime->ownerStorage);

            self::assertNotSame($global->storageRef->recordId, $beta->storageRef->recordId);
            $globalRead = $service->read('page.home', 'scope:global', $runtime->admin->actorRef);
            $betaRead = $betaService->read('page.home', 'scope:tenant-beta', $runtime->admin->actorRef);
            self::assertNotNull($globalRead);
            self::assertNotNull($betaRead);
            self::assertSame('scope:global', $globalRead->document['scope_ref']);
            self::assertSame('scope:tenant-beta', $betaRead->document['scope_ref']);
            self::assertSame(2, (int) $runtime->connection->table('larena_storage_records')->count());
        } finally {
            $runtime->close();
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
