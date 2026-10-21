<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SammyBoy\Reporting\Envelope\EnvelopeFormat;
use SammyBoy\Reporting\Envelope\ReportEnvelope;
use SammyBoy\Reporting\Envelope\ReportKind;
use SammyBoy\Reporting\Exceptions\InvalidReportException;

final class ReportEnvelopeTest extends TestCase
{
    private function envelope(array $payload = ['id' => 42, 'total' => 10.5]): ReportEnvelope
    {
        return new ReportEnvelope(
            eventId: 'evt-1',
            eventName: 'order.snapshot.synced',
            kind: ReportKind::Snapshot,
            schemaVersion: 3,
            occurredAt: new DateTimeImmutable('2026-10-13T10:00:00+03:00'),
            aggregateType: 'order',
            aggregateId: '42',
            source: 'sales-service',
            correlationId: 'corr-9',
            actorId: 7,
            payload: $payload,
        );
    }

    #[Test]
    public function flat_format_keeps_payload_fields_at_top_level_and_adds_metadata(): void
    {
        $data = $this->envelope()->toTransportData(EnvelopeFormat::Flat, '_reporting');

        $this->assertSame(42, $data['id']);
        $this->assertSame(10.5, $data['total']);
        $this->assertSame([
            'event_id' => 'evt-1',
            'event_name' => 'order.snapshot.synced',
            'kind' => 'snapshot',
            'schema_version' => 3,
            'occurred_at' => '2026-10-13T10:00:00+03:00',
            'aggregate_type' => 'order',
            'aggregate_id' => '42',
            'source' => 'sales-service',
            'correlation_id' => 'corr-9',
            'actor_id' => 7,
        ], $data['_reporting']);
        $this->assertSame(['id', 'total', '_reporting'], array_keys($data));
    }

    #[Test]
    public function wrapped_format_matches_the_auth_user_id_payload_convention(): void
    {
        $data = $this->envelope()->toTransportData(EnvelopeFormat::Wrapped, '_reporting');

        $this->assertSame(['auth_user_id', 'payload'], array_keys($data));
        $this->assertSame(7, $data['auth_user_id']);
        $this->assertSame(42, $data['payload']['id']);
        $this->assertSame('evt-1', $data['payload']['_reporting']['event_id']);
    }

    #[Test]
    public function payload_may_not_use_the_reserved_metadata_key(): void
    {
        $this->expectException(InvalidReportException::class);
        $this->expectExceptionMessage('reserved');

        $this->envelope(['id' => 1, '_reporting' => 'oops'])->toTransportData(EnvelopeFormat::Flat, '_reporting');
    }

    #[Test]
    public function metadata_key_is_configurable(): void
    {
        $data = $this->envelope()->toTransportData(EnvelopeFormat::Flat, '__meta');

        $this->assertArrayHasKey('__meta', $data);
        $this->assertArrayNotHasKey('_reporting', $data);
    }

    #[Test]
    public function round_trips_through_both_formats(): void
    {
        $original = $this->envelope();

        foreach (EnvelopeFormat::cases() as $format) {
            $decoded = ReportEnvelope::fromTransportData($original->toTransportData($format, '_reporting'));

            $this->assertNotNull($decoded, $format->value);
            $this->assertSame($original->eventId, $decoded->eventId);
            $this->assertSame($original->eventName, $decoded->eventName);
            $this->assertSame(ReportKind::Snapshot, $decoded->kind);
            $this->assertSame(3, $decoded->schemaVersion);
            $this->assertSame('2026-10-13T10:00:00+03:00', $decoded->occurredAt->format(DATE_ATOM));
            $this->assertSame('42', $decoded->aggregateId);
            $this->assertSame('corr-9', $decoded->correlationId);
            $this->assertSame(7, $decoded->actorId);
            $this->assertSame(['id' => 42, 'total' => 10.5], $decoded->payload);
        }
    }

    #[Test]
    public function decodes_from_a_full_eventbus_envelope(): void
    {
        $bus = [
            'meta' => ['id' => 'evt-1', 'type' => 'order.snapshot.synced', 'source' => 'x', 'timestamp' => 'now'],
            'data' => $this->envelope()->toTransportData(EnvelopeFormat::Flat, '_reporting'),
        ];

        $decoded = ReportEnvelope::fromTransportData($bus);

        $this->assertSame('evt-1', $decoded?->eventId);
        $this->assertSame(['id' => 42, 'total' => 10.5], $decoded->payload);
    }

    #[Test]
    public function returns_null_for_legacy_payloads_without_metadata(): void
    {
        $this->assertNull(ReportEnvelope::fromTransportData(['id' => 1, 'total' => 5]));
        $this->assertNull(ReportEnvelope::fromTransportData(['auth_user_id' => 1, 'payload' => ['id' => 1]]));
        $this->assertNull(ReportEnvelope::fromTransportData([]));
    }
}
