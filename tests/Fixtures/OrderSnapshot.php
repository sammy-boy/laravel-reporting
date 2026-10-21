<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Fixtures;

use DateTimeInterface;
use SammyBoy\Reporting\Contracts\Reportable;
use SammyBoy\Reporting\Envelope\ReportKind;

/**
 * A deliberately generic snapshot contract (no UzaPoint vocabulary) used to
 * exercise the publisher. Every field is overridable so tests can produce
 * invalid variants.
 */
final class OrderSnapshot implements Reportable
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventName = 'order.snapshot.synced',
        public ReportKind $kind = ReportKind::Snapshot,
        public int $schemaVersion = 1,
        public string $aggregateType = 'order',
        public string|int $aggregateId = 42,
        public ?DateTimeInterface $occurredAt = null,
        public ?string $idempotencyKey = null,
        public int|string|null $actorId = 7,
        public array $payload = ['id' => 42, 'total' => 150.5, 'items' => [['sku' => 'A', 'qty' => 2]]],
    ) {}

    public function eventName(): string
    {
        return $this->eventName;
    }

    public function kind(): ReportKind
    {
        return $this->kind;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function aggregateId(): string|int
    {
        return $this->aggregateId;
    }

    public function occurredAt(): ?DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function actorId(): int|string|null
    {
        return $this->actorId;
    }

    public function toReportPayload(): array
    {
        return $this->payload;
    }
}
