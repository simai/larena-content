<?php

declare(strict_types=1);

namespace Larena\Content\Audit;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;

final readonly class ContentAuditEventDescriptor implements AuditEventDescriptor
{
    public function __construct(private string $eventType)
    {
        if (!ContentAuditEventCatalog::contains($this->eventType)) {
            throw new InvalidArgumentException('content_audit_event_type_unknown');
        }
    }

    public function sourcePackage(): string
    {
        return 'larena/content';
    }

    public function category(): string
    {
        return match (true) {
            str_starts_with($this->eventType, 'content.type.') => 'content_schema',
            str_starts_with($this->eventType, 'content.attachment.') => 'content_attachment',
            $this->eventType === 'content.operation.denied' => 'content_security',
            default => 'content_lifecycle',
        };
    }

    public function type(): string
    {
        return $this->eventType;
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Security;
    }

    public function retentionClass(): AuditRetentionClass
    {
        return AuditRetentionClass::Security;
    }

    /**
     * @return list<string>
     */
    public function redactedPayloadFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function forbiddenPayloadFields(): array
    {
        return ContentAuditEventCatalog::forbiddenPayloadFields();
    }

    public function isExperimental(): bool
    {
        return false;
    }
}
