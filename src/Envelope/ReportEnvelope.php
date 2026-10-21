<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Envelope;

use DateTimeImmutable;
use DateTimeInterface;
use SammyBoy\Reporting\Exceptions\InvalidReportException;

/**
 * The canonical reporting message. Immutable.
 *
 * On the wire it rides inside eventbus-core's own envelope:
 *
 *   meta.id   <- eventId (via the bus's idempotency_key)
 *   meta.type <- eventName (routing key)
 *   data      <- payload framed by EnvelopeFormat, carrying metadata() under
 *                the configured metadata key
 *
 * The metadata block duplicates nothing a handler can already see: handlers
 * receive only `data`, so every field here would otherwise be lost.
 */
final readonly class ReportEnvelope
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventName,
        public ReportKind $kind,
        public int $schemaVersion,
        public DateTimeImmutable $occurredAt,
        public string $aggregateType,
        public string $aggregateId,
        public string $source,
        public ?string $correlationId,
        public int|string|null $actorId,
        public array $payload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'kind' => $this->kind->value,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'source' => $this->source,
            'correlation_id' => $this->correlationId,
            'actor_id' => $this->actorId,
        ];
    }

    /**
     * Frame this envelope as the `data` array eventbus-core will publish.
     *
     * @return array<string, mixed>
     */
    public function toTransportData(EnvelopeFormat $format, string $metadataKey): array
    {
        if (array_key_exists($metadataKey, $this->payload)) {
            throw InvalidReportException::reservedKey($this->eventName, $metadataKey);
        }

        $body = $this->payload + [$metadataKey => $this->metadata()];

        return match ($format) {
            EnvelopeFormat::Flat => $body,
            EnvelopeFormat::Wrapped => [
                'auth_user_id' => $this->actorId,
                'payload' => $body,
            ],
        };
    }

    /**
     * Rebuild an envelope from the `data` a handler received. Accepts either
     * framing, the full bus envelope ({meta, data}), or a legacy payload
     * published without this package — which yields null.
     *
     * @param array<string, mixed> $data
     */
    public static function fromTransportData(array $data, string $metadataKey = '_reporting'): ?self
    {
        $body = self::locateBody($data, $metadataKey);

        if ($body === null) {
            return null;
        }

        $meta = $body[$metadataKey];
        unset($body[$metadataKey]);

        return new self(
            eventId: (string) $meta['event_id'],
            eventName: (string) $meta['event_name'],
            kind: ReportKind::from((string) $meta['kind']),
            schemaVersion: (int) $meta['schema_version'],
            occurredAt: new DateTimeImmutable((string) $meta['occurred_at']),
            aggregateType: (string) $meta['aggregate_type'],
            aggregateId: (string) $meta['aggregate_id'],
            source: (string) ($meta['source'] ?? ''),
            correlationId: isset($meta['correlation_id']) ? (string) $meta['correlation_id'] : null,
            actorId: $meta['actor_id'] ?? null,
            payload: $body,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private static function locateBody(array $data, string $metadataKey): ?array
    {
        // Full eventbus-core envelope passed straight through.
        if (isset($data['meta'], $data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (isset($data[$metadataKey]) && is_array($data[$metadataKey])) {
            return $data;
        }

        // Wrapped format.
        if (isset($data['payload'][$metadataKey]) && is_array($data['payload'][$metadataKey])) {
            return $data['payload'];
        }

        return null;
    }
}
