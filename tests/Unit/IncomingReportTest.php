<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SammyBoy\Reporting\Envelope\IncomingReport;

final class IncomingReportTest extends TestCase
{
    private array $metadata = [
        'event_id' => 'evt-1',
        'event_name' => 'expense.created',
        'kind' => 'event',
        'schema_version' => 2,
        'occurred_at' => '2026-10-13T10:00:00+03:00',
        'aggregate_type' => 'expense',
        'aggregate_id' => '9',
        'source' => 'accounts-service',
        'correlation_id' => 'corr-1',
        'actor_id' => 3,
    ];

    #[Test]
    public function contracted_flat_payload(): void
    {
        $report = IncomingReport::from(['expense_id' => 9, 'amount' => 100, '_reporting' => $this->metadata]);

        $this->assertTrue($report->isContracted());
        $this->assertSame(2, $report->schemaVersion());
        $this->assertSame('corr-1', $report->correlationId());
        $this->assertSame(['expense_id' => 9, 'amount' => 100], $report->payload);
    }

    #[Test]
    public function contracted_wrapped_payload(): void
    {
        $report = IncomingReport::from([
            'auth_user_id' => 3,
            'payload' => ['expense_id' => 9, '_reporting' => $this->metadata],
        ]);

        $this->assertTrue($report->isContracted());
        $this->assertSame(['expense_id' => 9], $report->payload);
        $this->assertSame(3, $report->envelope?->actorId);
    }

    #[Test]
    public function legacy_flat_payload_is_passed_through_with_schema_version_zero(): void
    {
        $report = IncomingReport::from(['id' => 1, 'grand_total' => 50]);

        $this->assertFalse($report->isContracted());
        $this->assertSame(0, $report->schemaVersion());
        $this->assertNull($report->correlationId());
        $this->assertSame(['id' => 1, 'grand_total' => 50], $report->payload);
    }

    #[Test]
    public function legacy_wrapped_payload_is_unwrapped_like_existing_handlers_do(): void
    {
        $report = IncomingReport::from(['auth_user_id' => 3, 'payload' => ['expense_id' => 9]]);

        $this->assertFalse($report->isContracted());
        $this->assertSame(['expense_id' => 9], $report->payload);
    }

    #[Test]
    public function a_payload_key_alone_is_not_treated_as_wrapping(): void
    {
        // Some domain payloads legitimately contain a "payload" field.
        $report = IncomingReport::from(['id' => 1, 'payload' => ['nested' => true]]);

        $this->assertSame(['id' => 1, 'payload' => ['nested' => true]], $report->payload);
    }

    #[Test]
    public function full_bus_envelope_is_unwrapped(): void
    {
        $report = IncomingReport::from(['meta' => ['id' => 'x'], 'data' => ['id' => 5]]);

        $this->assertSame(['id' => 5], $report->payload);
    }
}
