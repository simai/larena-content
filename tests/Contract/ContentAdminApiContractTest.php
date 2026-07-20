<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Rest\Documentation\OpenApiGenerator;
use Larena\Rest\Enums\HttpMethod;
use Larena\Rest\Registry\CompiledApiOperationRegistry;
use Larena\Rest\Registry\PackageApiContractLoader;
use PHPUnit\Framework\TestCase;

final class ContentAdminApiContractTest extends TestCase
{
    /**
     * @var array<string, array{method:string,path:string,access:list<string>}>
     */
    private const OPERATIONS = [
        'content.type_admin.list' => ['method' => 'GET', 'path' => '/api/v1/admin/content/types', 'access' => ['content.type.list']],
        'content.type_admin.read' => ['method' => 'GET', 'path' => '/api/v1/admin/content/types/{type_key}', 'access' => ['content.type.read']],
        'content.type_admin.create' => ['method' => 'POST', 'path' => '/api/v1/admin/content/types', 'access' => ['content.type.create', 'storage.schema.create']],
        'content.type_admin.versions.list' => ['method' => 'GET', 'path' => '/api/v1/admin/content/types/{type_key}/versions', 'access' => ['content.type.read']],
        'content.type_admin.versions.read' => ['method' => 'GET', 'path' => '/api/v1/admin/content/types/{type_key}/versions/{version}', 'access' => ['content.type.read']],
        'content.type_admin.versions.preview' => ['method' => 'POST', 'path' => '/api/v1/admin/content/types/{type_key}/versions/preview', 'access' => ['content.type.version.preview', 'content.type.read', 'storage.schema_migration.diff', 'storage.record.read']],
        'content.type_admin.versions.create' => ['method' => 'POST', 'path' => '/api/v1/admin/content/types/{type_key}/versions', 'access' => ['content.type.version.create', 'content.type.read', 'storage.schema_migration.diff', 'storage.schema_migration.plan', 'storage.schema_migration.dispatch', 'storage.record.read']],
        'content.item_admin.list' => ['method' => 'GET', 'path' => '/api/v1/admin/content/items', 'access' => ['content.item.list']],
        'content.item_admin.read' => ['method' => 'GET', 'path' => '/api/v1/admin/content/items/{item_ref}', 'access' => ['content.item.read', 'content.revision.read', 'content.type.read', 'storage.record.read']],
        'content.item_admin.create' => ['method' => 'POST', 'path' => '/api/v1/admin/content/items', 'access' => ['content.item.create', 'storage.record.create']],
        'content.item_admin.update' => ['method' => 'PUT', 'path' => '/api/v1/admin/content/items/{item_ref}', 'access' => ['content.item.update', 'storage.record.update']],
        'content.item_admin.revisions.list' => ['method' => 'GET', 'path' => '/api/v1/admin/content/items/{item_ref}/revisions', 'access' => ['content.revision.list']],
        'content.item_admin.revisions.read' => ['method' => 'GET', 'path' => '/api/v1/admin/content/items/{item_ref}/revisions/{revision}', 'access' => ['content.revision.read', 'content.type.read', 'storage.record.read']],
        'content.item_admin.revisions.restore' => ['method' => 'POST', 'path' => '/api/v1/admin/content/items/{item_ref}/revisions/{revision}/restore', 'access' => ['content.item.restore', 'storage.record.read', 'storage.record.update']],
        'content.item_admin.publish' => ['method' => 'POST', 'path' => '/api/v1/admin/content/items/{item_ref}/publish', 'access' => ['content.item.publish']],
        'content.item_admin.unpublish' => ['method' => 'POST', 'path' => '/api/v1/admin/content/items/{item_ref}/unpublish', 'access' => ['content.item.unpublish']],
        'content.item_admin.attachments.list' => ['method' => 'GET', 'path' => '/api/v1/admin/content/items/{item_ref}/attachments', 'access' => ['content.attachment.list']],
        'content.item_admin.attachments.attach' => ['method' => 'POST', 'path' => '/api/v1/admin/content/items/{item_ref}/attachments', 'access' => ['content.attachment.attach']],
        'content.item_admin.attachments.detach' => ['method' => 'DELETE', 'path' => '/api/v1/admin/content/items/{item_ref}/attachments/{logical_file_ref}', 'access' => ['content.attachment.detach']],
        'content.item_admin.attachments.reorder' => ['method' => 'PUT', 'path' => '/api/v1/admin/content/items/{item_ref}/attachments', 'access' => ['content.attachment.reorder']],
    ];

    public function test_real_loader_compiles_exact_twenty_operation_contract(): void
    {
        $contract = (new PackageApiContractLoader())->loadFile(
            dirname(__DIR__, 2).'/api.yaml',
            'larena/content',
        );

        self::assertSame('larena/content', $contract->package);
        self::assertSame('1.0.0', $contract->version);
        self::assertCount(20, $contract->operations);
        self::assertSame(array_keys(self::OPERATIONS), array_map(
            static fn ($operation): string => $operation->operationKey,
            $contract->operations,
        ));

        foreach ($contract->operations as $operation) {
            $expected = self::OPERATIONS[$operation->operationKey];
            self::assertSame($operation->operationKey, $operation->handlerReference);
            self::assertSame($expected['method'], $operation->method->value);
            self::assertSame($expected['path'], $operation->path);
            self::assertSame($expected['access'], $operation->accessOperations);
            self::assertSame(['admin_session'], $operation->authChannels);
            self::assertSame('none', $operation->requiredAssurance);
            self::assertSame('no-store', $operation->cacheControl);

            if ($operation->method === HttpMethod::Get) {
                self::assertFalse($operation->csrfRequired);
                self::assertFalse($operation->transactional);
                self::assertFalse($operation->idempotencyRequired);
            } else {
                self::assertTrue($operation->csrfRequired);
                self::assertTrue($operation->transactional);
                self::assertTrue($operation->idempotencyRequired);
            }

            if ($operation->operationKey === 'content.type_admin.versions.create') {
                self::assertSame(['type'], $operation->responseSchema['required']);
                self::assertSame(
                    ['type'],
                    array_keys($operation->responseSchema['properties']),
                );
                self::assertSame(
                    ['type_key', 'current_version'],
                    $operation->responseSchema['properties']['type']['required'],
                );
            }
        }
    }

    public function test_openapi_is_generated_from_the_same_content_contract(): void
    {
        $contract = (new PackageApiContractLoader())->loadFile(
            dirname(__DIR__, 2).'/api.yaml',
            'larena/content',
        );
        $document = (new OpenApiGenerator(
            new CompiledApiOperationRegistry([$contract]),
        ))->generate(static fn ($operation): bool => $operation->ownerPackage === 'larena/content');

        self::assertSame('3.1.0', $document['openapi']);
        self::assertCount(14, $document['paths']);
        foreach (self::OPERATIONS as $operationKey => $expected) {
            $method = strtolower($expected['method']);
            self::assertSame(
                $operationKey,
                $document['paths'][$expected['path']][$method]['operationId'] ?? null,
            );
        }
    }
}
