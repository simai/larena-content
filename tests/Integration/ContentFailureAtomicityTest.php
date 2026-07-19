<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Integration;

use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;

final class ContentFailureAtomicityTest extends TestCase
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

    public function test_required_domain_denial_audit_failure_returns_sanitized_integration_failure(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $scenario->createArticle('collision');
        $before = [
            'items' => $this->runtime->connection->table('larena_content_items')->count(),
            'revisions' => $this->runtime->connection->table('larena_content_item_revisions')->count(),
            'storage' => $this->runtime->connection->table('larena_storage_records')->count(),
            'audit' => $this->runtime->connection->table('larena_audit_events')->count(),
        ];
        $this->runtime->connection->unprepared(
            "CREATE TRIGGER fail_content_denial_audit
             BEFORE INSERT ON larena_audit_events
             WHEN NEW.source_package = 'larena/content'
                  AND NEW.event_type = 'content.operation.denied'
             BEGIN
                 SELECT RAISE(ABORT, 'private_denial_sink_detail');
             END",
        );

        try {
            $scenario->createArticle('collision');
            self::fail('Duplicate slug unexpectedly survived required denial handling.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('audit', $exception->integration);
            self::assertSame('content_audit_write_failed', $exception->reasonCode);
            self::assertStringNotContainsString('private_denial_sink_detail', $exception->getMessage());
        }

        self::assertSame($before, [
            'items' => $this->runtime->connection->table('larena_content_items')->count(),
            'revisions' => $this->runtime->connection->table('larena_content_item_revisions')->count(),
            'storage' => $this->runtime->connection->table('larena_storage_records')->count(),
            'audit' => $this->runtime->connection->table('larena_audit_events')->count(),
        ]);
    }
}
