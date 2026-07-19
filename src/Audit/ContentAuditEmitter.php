<?php

declare(strict_types=1);

namespace Larena\Content\Audit;

use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\ConnectionBoundAuditEventPipeline;
use Larena\Content\Contracts\ContentClock;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;
use Throwable;

final readonly class ContentAuditEmitter
{
    public function __construct(
        private ConnectionBoundAuditEventPipeline $pipeline,
        private ContentClock $clock,
    ) {
    }

    /**
     * Caller owns the surrounding canonical or audit-only transaction.
     */
    public function emit(
        string $eventType,
        ActorContext $actor,
        string $subject,
        ContentAuditPayload $payload,
    ): AuditEvent {
        if (!ContentAuditEventCatalog::contains($eventType)) {
            throw new ContentRejected(
                'audit_event_unknown',
                'The Content Audit event type is outside the frozen catalog.',
            );
        }

        if (
            $subject === ''
            || strlen($subject) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
        ) {
            throw new ContentRejected(
                'audit_subject_invalid',
                'The Content Audit subject is invalid.',
            );
        }

        $occurredAt = $this->clock->now();
        $descriptor = new ContentAuditEventDescriptor($eventType);

        try {
            $event = AuditEvent::create(
                sourcePackage: $descriptor->sourcePackage(),
                category: $descriptor->category(),
                type: $descriptor->type(),
                actor: $actor->actorRef,
                subject: $subject,
                severity: $descriptor->severity(),
                retentionClass: $descriptor->retentionClass(),
                correlationId: $actor->correlationId,
                occurredAt: $occurredAt,
                payload: $payload->withActorContext($actor, $occurredAt),
            );

            return $this->pipeline->route($descriptor, $event);
        } catch (ContentRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // No recursive denial emission: the caller receives one sanitized
            // integration failure while the canonical transaction stays zero.
            throw new ContentIntegrationFailed(
                'audit',
                'content_audit_write_failed',
                $exception,
            );
        }
    }

    /**
     * Emit only after Access succeeded and no canonical transaction is active,
     * or after the canonical transaction has rolled back.
     *
     * @param array<string, mixed> $context
     */
    public function domainDenied(
        ActorContext $actor,
        string $operation,
        string $reasonCode,
        string $subject,
        array $context = [],
    ): AuditEvent {
        return $this->emit(
            'content.operation.denied',
            $actor,
            $subject,
            ContentAuditPayload::domainDenial($operation, $reasonCode, $context),
        );
    }
}
