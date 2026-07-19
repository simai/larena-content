<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentTypeKey;

final class ContentAuthorizationRuntimeTest extends TestCase
{
    private ContentRuntimeHarness $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = ContentRuntimeHarness::create();
    }

    protected function tearDown(): void
    {
        $this->runtime->close();
        parent::tearDown();
    }

    public function test_access_denial_is_emitted_exactly_once_and_never_duplicated_by_content(): void
    {
        $beforeAccess = $this->runtime->accessDenialCount();
        $beforeContent = $this->runtime->contentAuditCount('content.operation.denied');

        try {
            $fields = ContentPlatformV1Fixture::articleFields();
            $this->runtime->types->create(
                new ContentTypeKey('forbidden'),
                $fields,
                ContentPlatformV1Fixture::articleProjectionContract(),
                ['label' => 'Forbidden'],
                $this->runtime->reader,
            );
            self::fail('Reader unexpectedly created a Content type.');
        } catch (AccessMutationRejected $exception) {
            self::assertSame('access_actor_forbidden', $exception->reasonCode);
        }

        self::assertSame($beforeAccess + 1, $this->runtime->accessDenialCount());
        self::assertSame(
            $beforeContent,
            $this->runtime->contentAuditCount('content.operation.denied'),
        );
        self::assertSame(0, $this->runtime->connection->table('larena_content_types')->count());
        self::assertSame(0, $this->runtime->connection->table('larena_storage_schemas')->count());
    }

    public function test_domain_rejection_after_access_emits_one_content_denial_only(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $scenario->createArticle('collision');
        $beforeAccess = $this->runtime->accessDenialCount();
        $beforeContent = $this->runtime->contentAuditCount('content.operation.denied');

        try {
            $scenario->createArticle('collision');
            self::fail('A duplicate slug was accepted.');
        } catch (ContentRejected $exception) {
            self::assertSame('slug_conflict', $exception->reasonCode());
        }

        self::assertSame($beforeAccess, $this->runtime->accessDenialCount());
        self::assertSame(
            $beforeContent + 1,
            $this->runtime->contentAuditCount('content.operation.denied'),
        );
        self::assertSame(1, $this->runtime->connection->table('larena_content_items')->count());
        self::assertSame(1, $this->runtime->connection->table('larena_storage_records')->count());
    }
}
