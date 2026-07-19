<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use InvalidArgumentException;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Content\Audit\ContentAuditEventCatalog;
use Larena\Content\Audit\ContentAuditEventDescriptor;
use Larena\Content\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AuditDescriptorContractTest extends TestCase
{
    private const EXPECTED_TYPES = [
        'content.type.created',
        'content.item.created',
        'content.item.updated',
        'content.item.restored',
        'content.item.published',
        'content.item.unpublished',
        'content.attachment.attached',
        'content.attachment.detached',
        'content.attachment.reordered',
        'content.operation.denied',
    ];

    private const EXPECTED_FORBIDDEN_FIELDS = [
        'values',
        'raw_value',
        'typed_values',
        'raw_values',
        'body',
        'content',
        'fields',
        'file_bytes',
        'filesystem_path',
        'storage_path',
        'storage_key',
        'signed_url',
        'credentials',
        'authorization',
        'password',
        'token',
        'cookie',
        'session',
        'secret',
        'api_key',
        'private_metadata',
        'request_headers',
    ];

    private const EXPECTED_ALLOWED_FIELDS = [
        'operation',
        'reason_code',
        'actor_type',
        'actor_ref',
        'type_key',
        'item_ref',
        'logical_file_ref',
        'expected_revision',
        'current_revision',
        'new_revision',
        'status',
        'visibility',
        'field_count',
        'attachment_count',
        'correlation_id',
        'timestamp',
    ];

    public function test_catalog_contains_exact_sanitized_event_taxonomy(): void
    {
        self::assertSame(self::EXPECTED_TYPES, ContentAuditEventCatalog::types());
        self::assertSame(
            self::EXPECTED_FORBIDDEN_FIELDS,
            ContentAuditEventCatalog::forbiddenPayloadFields(),
        );
        self::assertSame(
            self::EXPECTED_ALLOWED_FIELDS,
            ContentAuditEventCatalog::allowedPayloadFields(),
        );

        foreach (ContentAuditEventCatalog::descriptors() as $descriptor) {
            self::assertSame('larena/content', $descriptor->sourcePackage());
            self::assertSame(AuditSeverity::Security, $descriptor->severity());
            self::assertSame(AuditRetentionClass::Security, $descriptor->retentionClass());
            self::assertSame(self::EXPECTED_FORBIDDEN_FIELDS, $descriptor->forbiddenPayloadFields());
            self::assertFalse($descriptor->isExperimental());
        }
    }

    public function test_unknown_event_type_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('content_audit_event_type_unknown');

        new ContentAuditEventDescriptor('content.item.deleted');
    }

    public function test_dependency_redactor_rejects_forbidden_fields_recursively(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit payload contains forbidden field: values');

        (new DefaultAuditRedactor())->redact(
            ['safe' => ['nested' => ['values' => ['private']]]],
            [],
            ContentAuditEventCatalog::forbiddenPayloadFields(),
        );
    }

    public function test_yaml_and_php_catalogs_are_identical(): void
    {
        $descriptor = Yaml::parseFile(dirname(__DIR__, 2) . '/audit.yaml');

        self::assertIsArray($descriptor);
        self::assertSame('larena.audit.package-events.v1', $descriptor['schema']);
        self::assertSame('larena/content', $descriptor['package']);
        self::assertSame(self::EXPECTED_TYPES, array_column($descriptor['events'], 'type'));
        self::assertSame(self::EXPECTED_ALLOWED_FIELDS, $descriptor['allowed_payload_fields']);
        self::assertSame(self::EXPECTED_FORBIDDEN_FIELDS, $descriptor['forbidden_payload_fields']);
        self::assertTrue($descriptor['atomic_completion']);
        self::assertFalse($descriptor['nonclaims']['production_ready']);
        self::assertFalse($descriptor['nonclaims']['all_packages_ready']);
    }
}
