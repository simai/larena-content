<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Feature;

use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Tests\Support\ContentPlatformScenario;
use Larena\Content\Tests\Support\ContentRuntimeHarness;
use Larena\Content\Tests\TestCase;

final class ContentAtomicityRuntimeTest extends TestCase
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

    public function test_search_failure_rolls_back_publication_content_routes_and_search_state(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $draft = $scenario->createArticle();
        $beforeAudit = $this->runtime->contentAuditCount();

        $this->runtime->connection->unprepared(
            "CREATE TRIGGER fail_content_search_insert
             BEFORE INSERT ON larena_search_documents
             BEGIN
                 SELECT RAISE(ABORT, 'fixture_search_failure');
             END",
        );

        try {
            $this->runtime->items->publish(
                $draft->itemRef,
                1,
                $this->runtime->actor(),
            );
            self::fail('Publication unexpectedly survived Search failure.');
        } catch (\Throwable $exception) {
            self::assertStringNotContainsString('fixture_search_failure', $exception->getMessage());
        }

        $head = $this->runtime->items->read(
            $draft->itemRef,
            $this->runtime->actor(),
        );
        self::assertSame(1, $head->currentRevision);
        self::assertNull($head->publishedRevision);
        self::assertSame(1, $this->runtime->connection->table('larena_content_item_revisions')->count());
        self::assertSame(0, $this->runtime->connection->table('larena_search_documents')->count());
        self::assertSame(0, $this->runtime->connection->table('larena_search_source_states')->count());
        self::assertSame($beforeAudit, $this->runtime->contentAuditCount());
    }

    public function test_content_audit_failure_rolls_back_item_and_owner_storage_write(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $beforeStorage = $this->runtime->connection->table('larena_storage_records')->count();
        $this->runtime->connection->unprepared(
            "CREATE TRIGGER fail_content_audit_insert
             BEFORE INSERT ON larena_audit_events
             WHEN NEW.source_package = 'larena/content'
             BEGIN
                 SELECT RAISE(ABORT, 'fixture_audit_failure');
             END",
        );

        try {
            $scenario->createArticle('audit-failure');
            self::fail('Content mutation unexpectedly survived Audit failure.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('audit', $exception->integration);
            self::assertSame('content_audit_write_failed', $exception->reasonCode);
            self::assertStringNotContainsString('fixture_audit_failure', $exception->getMessage());
        }

        self::assertSame(0, $this->runtime->connection->table('larena_content_items')->count());
        self::assertSame($beforeStorage, $this->runtime->connection->table('larena_storage_records')->count());
        self::assertSame(0, $this->runtime->connection->table('larena_storage_record_versions')->count());
    }

    public function test_storage_failure_leaves_zero_content_rows_and_no_content_audit(): void
    {
        $scenario = new ContentPlatformScenario($this->runtime);
        $scenario->createArticleType();
        $beforeAudit = $this->runtime->contentAuditCount();
        $this->runtime->connection->unprepared(
            "CREATE TRIGGER fail_storage_record_insert
             BEFORE INSERT ON larena_storage_records
             BEGIN
                 SELECT RAISE(ABORT, 'fixture_storage_failure');
             END",
        );

        try {
            $scenario->createArticle('storage-failure');
            self::fail('Content mutation unexpectedly survived Storage failure.');
        } catch (\Throwable $exception) {
            self::assertStringNotContainsString('fixture_storage_failure', $exception->getMessage());
        }

        self::assertSame(0, $this->runtime->connection->table('larena_content_items')->count());
        self::assertSame(0, $this->runtime->connection->table('larena_content_item_revisions')->count());
        self::assertSame(0, $this->runtime->connection->table('larena_storage_records')->count());
        self::assertSame($beforeAudit, $this->runtime->contentAuditCount());
    }
}
