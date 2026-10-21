<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Envelope;

use DateTimeImmutable;
use Illuminate\Support\Str;
use SammyBoy\Reporting\Contracts\Reportable;
use SammyBoy\Reporting\Exceptions\InvalidReportException;
use SammyBoy\Reporting\Tracing\CorrelationId;

/**
 * Turns a Reportable into a validated ReportEnvelope. All producer-side
 * validation lives here so a malformed contract fails before anything is
 * deferred to after-commit or handed to the bus.
 */
final class EnvelopeFactory
{
    private const string EVENT_NAME_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

    public function __construct(
        private readonly CorrelationId $correlation,
        private readonly string $source,
    ) {}

    public function make(Reportable $report, ?string $correlationId = null): ReportEnvelope
    {
        $class = $report::class;
        $eventName = $report->eventName();
        $aggregateType = trim($report->aggregateType());
        $aggregateId = trim((string) $report->aggregateId());
        $schemaVersion = $report->schemaVersion();

        if (preg_match(self::EVENT_NAME_PATTERN, $eventName) !== 1) {
            throw InvalidReportException::invalidEventName($class, $eventName);
        }

        if ($aggregateType === '') {
            throw InvalidReportException::emptyAggregateType($class);
        }

        if ($aggregateId === '') {
            throw InvalidReportException::emptyAggregateId($class);
        }

        if ($schemaVersion < 1) {
            throw InvalidReportException::invalidSchemaVersion($class, $schemaVersion);
        }

        $occurredAt = $report->occurredAt();

        return new ReportEnvelope(
            eventId: $report->idempotencyKey() ?? (string) Str::orderedUuid(),
            eventName: $eventName,
            kind: $report->kind(),
            schemaVersion: $schemaVersion,
            occurredAt: $occurredAt === null
                ? new DateTimeImmutable()
                : DateTimeImmutable::createFromInterface($occurredAt),
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            source: $this->source,
            correlationId: $correlationId ?? $this->correlation->current(),
            actorId: $report->actorId(),
            payload: $report->toReportPayload(),
        );
    }
}
