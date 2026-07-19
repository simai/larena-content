<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\ConnectionBoundAuditEventPipeline;
use Larena\Content\Audit\ContentAuditEmitter;
use Larena\Content\Audit\ContentAuditPayload;
use Larena\Content\Contracts\ContentClock;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ActorContext;
use RuntimeException;

final class ContentAuditEmitterTest extends TestCase
{
    public function testSuccessEventUsesExactActorClockAndSafePayload(): void
    {
        $pipeline = $this->createMock(ConnectionBoundAuditEventPipeline::class);
        $pipeline->expects(self::once())
            ->method('route')
            ->willReturnCallback(function (
                AuditEventDescriptor $descriptor,
                AuditEvent $event,
            ): AuditEvent {
                self::assertSame('content.item.created', $descriptor->type());
                self::assertSame('larena/content', $event->sourcePackage);
                self::assertSame('user:admin_identity:7', $event->actor);
                self::assertSame('content:item:test', $event->subject);
                self::assertSame('content-test-correlation', $event->correlationId);
                self::assertSame([
                    'actor_ref' => 'user:admin_identity:7',
                    'actor_type' => 'user',
                    'correlation_id' => 'content-test-correlation',
                    'field_count' => 3,
                    'item_ref' => 'content:item:test',
                    'timestamp' => '2026-07-19T10:11:12.123456Z',
                    'type_key' => 'article',
                ], $event->payload);

                return $event;
            });

        $emitter = new ContentAuditEmitter(
            $pipeline,
            new FixedContentClock(new DateTimeImmutable(
                '2026-07-19T10:11:12.123456+00:00',
                new DateTimeZone('UTC'),
            )),
        );
        $actor = new ActorContext(
            'user',
            'user:admin_identity:7',
            'content-test-correlation',
        );

        $event = $emitter->emit(
            'content.item.created',
            $actor,
            'content:item:test',
            ContentAuditPayload::from([
                'type_key' => 'article',
                'item_ref' => 'content:item:test',
                'field_count' => 3,
            ]),
        );

        self::assertSame('content.item.created', $event->type);
    }

    public function testDomainDenialContainsOnlyOneFrozenDenialPayload(): void
    {
        $pipeline = $this->createMock(ConnectionBoundAuditEventPipeline::class);
        $pipeline->expects(self::once())
            ->method('route')
            ->willReturnCallback(function (
                AuditEventDescriptor $descriptor,
                AuditEvent $event,
            ): AuditEvent {
                self::assertSame('content.operation.denied', $descriptor->type());
                self::assertSame('content.item.update', $event->payload['operation']);
                self::assertSame('stale_revision', $event->payload['reason_code']);
                self::assertSame(3, $event->payload['expected_revision']);
                self::assertSame(4, $event->payload['current_revision']);
                self::assertArrayNotHasKey('values', $event->payload);

                return $event;
            });

        $emitter = new ContentAuditEmitter(
            $pipeline,
            new FixedContentClock(new DateTimeImmutable('2026-07-19T10:11:12+00:00')),
        );

        $emitter->domainDenied(
            new ActorContext('user', 'user:admin_identity:7', 'denial-correlation'),
            'content.item.update',
            'stale_revision',
            'content:item:test',
            ['expected_revision' => 3, 'current_revision' => 4],
        );
    }

    public function testForbiddenPayloadAndPipelineFailureFailClosed(): void
    {
        try {
            ContentAuditPayload::from(['values' => 'secret']);
            self::fail('Expected the frozen Audit payload allowlist to reject typed values.');
        } catch (ContentRejected $exception) {
            self::assertSame('audit_payload_invalid', $exception->reasonCode());
        }

        $pipeline = $this->createStub(ConnectionBoundAuditEventPipeline::class);
        $pipeline->method('route')->willThrowException(new RuntimeException('database unavailable'));
        $emitter = new ContentAuditEmitter(
            $pipeline,
            new FixedContentClock(new DateTimeImmutable('2026-07-19T10:11:12+00:00')),
        );

        try {
            $emitter->emit(
                'content.item.updated',
                new ActorContext('user', 'user:admin_identity:7', 'failure-correlation'),
                'content:item:test',
                ContentAuditPayload::from(['new_revision' => 2]),
            );
            self::fail('Expected a sanitized Audit integration failure.');
        } catch (ContentIntegrationFailed $exception) {
            self::assertSame('audit', $exception->integration);
            self::assertSame('content_audit_write_failed', $exception->reasonCode);
        }
    }
}

final readonly class FixedContentClock implements ContentClock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
