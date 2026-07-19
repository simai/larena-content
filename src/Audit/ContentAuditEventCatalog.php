<?php

declare(strict_types=1);

namespace Larena\Content\Audit;

final class ContentAuditEventCatalog
{
    /**
     * @var list<string>
     */
    private const TYPES = [
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

    /**
     * Runtime event builders must select from this allowlist before passing a
     * payload to larena/audit.
     *
     * @var list<string>
     */
    private const ALLOWED_PAYLOAD_FIELDS = [
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

    /**
     * This denylist is consumed by larena/audit's recursive redactor.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PAYLOAD_FIELDS = [
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

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return self::TYPES;
    }

    /**
     * @return list<ContentAuditEventDescriptor>
     */
    public static function descriptors(): array
    {
        return array_map(
            static fn (string $type): ContentAuditEventDescriptor => new ContentAuditEventDescriptor($type),
            self::TYPES,
        );
    }

    /**
     * @return list<string>
     */
    public static function forbiddenPayloadFields(): array
    {
        return self::FORBIDDEN_PAYLOAD_FIELDS;
    }

    /**
     * @return list<string>
     */
    public static function allowedPayloadFields(): array
    {
        return self::ALLOWED_PAYLOAD_FIELDS;
    }

    public static function contains(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }
}
