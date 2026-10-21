<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Envelope;

/**
 * Consumer-side view of whatever a handler received from eventbus-core.
 *
 * During migration a handler receives two generations of message for the same
 * routing key: legacy payloads published by hand, and contracted payloads
 * published through this package. IncomingReport normalises both so the
 * handler can hydrate its contract from payload() and branch on
 * schemaVersion() without caring which generation arrived.
 */
final readonly class IncomingReport
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public ?ReportEnvelope $envelope,
        public array $payload,
    ) {}

    /**
     * @param array<string, mixed> $data exactly what the handler was given
     */
    public static function from(array $data, string $metadataKey = '_reporting'): self
    {
        $envelope = ReportEnvelope::fromTransportData($data, $metadataKey);

        if ($envelope !== null) {
            return new self($envelope, $envelope->payload);
        }

        return new self(null, self::legacyPayload($data));
    }

    public function isContracted(): bool
    {
        return $this->envelope !== null;
    }

    /**
     * 0 for legacy (uncontracted) payloads so callers can treat "0" as
     * "pre-contract shape" in a match/switch.
     */
    public function schemaVersion(): int
    {
        return $this->envelope?->schemaVersion ?? 0;
    }

    public function correlationId(): ?string
    {
        return $this->envelope?->correlationId;
    }

    /**
     * Mirrors the unwrapping existing handlers already perform by hand:
     * `$data['data'] ?? $data` and `$payload['payload'] ?? $payload`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function legacyPayload(array $data): array
    {
        if (isset($data['meta'], $data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if (isset($data['payload']) && is_array($data['payload']) && array_key_exists('auth_user_id', $data)) {
            return $data['payload'];
        }

        return $data;
    }
}
