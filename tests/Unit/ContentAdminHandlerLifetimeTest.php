<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Unit;

use Larena\Content\Contracts\ContentItemService;
use Larena\Content\Contracts\ContentTypeService;
use Larena\Content\Rest\ContentAdminApiOperationHandler;
use Larena\Content\Rest\ContentAdminReadModel;
use Larena\Content\Rest\ContentAdminValueCodec;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Rest\Exceptions\ApiOperationException;
use Larena\Rest\Registry\MutableOperationHandlerRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentAdminHandlerLifetimeTest extends TestCase
{
    public function test_lazy_registry_resolves_a_fresh_scoped_handler_for_every_dispatch(): void
    {
        $registry = new MutableOperationHandlerRegistry();
        $resolutions = 0;
        ContentAdminApiOperationHandler::registerLazy(
            $registry,
            function () use (&$resolutions): ContentAdminApiOperationHandler {
                $resolutions++;

                /** @var ContentAdminReadModel $reads */
                $reads = (new ReflectionClass(ContentAdminReadModel::class))
                    ->newInstanceWithoutConstructor();

                return new ContentAdminApiOperationHandler(
                    $this->createStub(ContentTypeService::class),
                    $this->createStub(ContentItemService::class),
                    $reads,
                    new ContentAdminValueCodec(),
                );
            },
        );

        $handler = $registry->get('content.type_admin.list');
        self::assertIsCallable($handler);
        $descriptor = new OperationDescriptor(
            'content.type_admin.list',
            OperationExecutionMode::Sync,
        );
        $context = new OperationContext(
            'user:admin_identity:1',
            'content-handler-lifetime',
        );

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $handler(['path' => [], 'query' => [], 'body' => []], $descriptor, $context);
                self::fail('A Content admin handler accepted a missing authenticated session entry object.');
            } catch (ApiOperationException $exception) {
                self::assertSame('content_admin_api_session_context_invalid', $exception->errorCode);
            }
            self::assertSame($attempt, $resolutions);
        }
    }
}
