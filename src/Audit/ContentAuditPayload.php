<?php

declare(strict_types=1);

namespace Larena\Content\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;

final readonly class ContentAuditPayload
{
    /**
     * @param array<string, bool|int|string|null> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function from(array $values): self
    {
        if ($values !== [] && array_is_list($values)) {
            throw new ContentRejected(
                'audit_payload_invalid',
                'A Content Audit payload must be a keyed object.',
            );
        }

        $allowed = array_fill_keys(ContentAuditEventCatalog::allowedPayloadFields(), true);
        $normalized = [];

        foreach ($values as $key => $value) {
            if (
                !is_string($key)
                || !isset($allowed[$key])
                || !($value === null || is_bool($value) || is_int($value) || is_string($value))
            ) {
                throw new ContentRejected(
                    'audit_payload_invalid',
                    'A Content Audit payload is outside the frozen scalar allowlist.',
                );
            }

            if (
                is_string($value)
                && (
                    preg_match('//u', $value) !== 1
                    || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
                    || strlen($value) > 500
                )
            ) {
                throw new ContentRejected(
                    'audit_payload_invalid',
                    'Content Audit text must be bounded control-free UTF-8.',
                );
            }

            $normalized[$key] = $value;
        }

        ksort($normalized, SORT_STRING);

        return new self($normalized);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function domainDenial(
        string $operation,
        string $reasonCode,
        array $context = [],
    ): self {
        if (
            array_key_exists('operation', $context)
            || array_key_exists('reason_code', $context)
        ) {
            throw new ContentRejected(
                'audit_payload_invalid',
                'Content denial context cannot override operation or reason code.',
            );
        }

        return self::from([
            ...$context,
            'operation' => $operation,
            'reason_code' => $reasonCode,
        ]);
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function withActorContext(ActorContext $actor, DateTimeImmutable $occurredAt): array
    {
        $timestamp = $occurredAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
        $context = [
            'actor_type' => $actor->actorType,
            'actor_ref' => $actor->actorRef,
            'correlation_id' => $actor->correlationId,
            'timestamp' => $timestamp,
        ];

        foreach ($context as $key => $value) {
            if (array_key_exists($key, $this->values) && $this->values[$key] !== $value) {
                throw new ContentRejected(
                    'audit_payload_context_mismatch',
                    'Content Audit payload context must match the governing actor context.',
                );
            }
        }

        $values = array_replace($this->values, $context);
        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
