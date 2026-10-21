<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SammyBoy\Reporting\Envelope\ReportKind;
use SammyBoy\Reporting\Events\ReportPublished;
use SammyBoy\Reporting\Events\ReportPublishFailed;
use SammyBoy\Reporting\Events\ReportPublishing;
use SammyBoy\Reporting\Exceptions\InvalidReportException;
use SammyBoy\Reporting\Exceptions\ReportPublishFailedException;
use SammyBoy\Reporting\Exceptions\UnroutableReportException;
use SammyBoy\Reporting\Facades\Reporting as ReportingFacade;
use SammyBoy\Reporting\Reporting;
use SammyBoy\Reporting\Testing\FakeTransport;
use SammyBoy\Reporting\Tests\Fixtures\OrderSnapshot;
use SammyBoy\Reporting\Tests\TestCase;
use SammyBoy\Reporting\Tracing\CorrelationId;

final class PublishingTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = ReportingFacade::fake();
    }

    private function reporting(): Reporting
    {
        return $this->app->make(Reporting::class);
    }

    #[Test]
    public function publishes_a_snapshot_to_the_default_exchange_with_flat_framing(): void
    {
        $envelope = $this->reporting()->publish(new OrderSnapshot());

        $this->transport->assertPublishedTimes('order.snapshot.synced', 1);
        [$published] = $this->transport->published();

        $this->assertSame('sales.events', $published->exchange);
        $this->assertSame($envelope, $published->envelope);
        $this->assertSame(ReportKind::Snapshot, $envelope->kind);
        $this->assertSame('sales-service', $envelope->source);
        $this->assertSame('42', $envelope->aggregateId);

        // Flat: handler-visible fields stay where the legacy handler expects them.
        $this->assertSame(42, $published->data['id']);
        $this->assertSame(150.5, $published->data['total']);
        $this->assertSame(1, $published->data['_reporting']['schema_version']);
        $this->assertSame($envelope->eventId, $published->data['_reporting']['event_id']);
    }

    #[Test]
    public function routes_by_event_name_override(): void
    {
        $this->reporting()->publish(new OrderSnapshot(eventName: 'invoice.snapshot.synced', aggregateType: 'invoice'));

        $this->assertSame('billing.events', $this->transport->published()[0]->exchange);
    }

    #[Test]
    public function wrapped_framing_can_be_selected_per_event(): void
    {
        config()->set('reporting.envelope.formats', ['order.*' => 'wrapped']);
        $this->app->forgetInstance(\SammyBoy\Reporting\Routing\EnvelopeFormatResolver::class);
        $this->app->forgetInstance(Reporting::class);

        $this->reporting()->publish(new OrderSnapshot(actorId: 99));

        $data = $this->transport->published()[0]->data;
        $this->assertSame(99, $data['auth_user_id']);
        $this->assertSame(42, $data['payload']['id']);
        $this->assertSame('order.snapshot.synced', $data['payload']['_reporting']['event_name']);
    }

    #[Test]
    public function snapshot_and_event_verbs_guard_the_declared_kind(): void
    {
        $snapshot = new OrderSnapshot();
        $event = new OrderSnapshot(eventName: 'order.paid', kind: ReportKind::Event);

        $this->reporting()->snapshot($snapshot);
        $this->reporting()->event($event);
        $this->transport->assertPublishedTimes('order.snapshot.synced', 1);
        $this->transport->assertPublishedTimes('order.paid', 1);

        $this->expectException(InvalidReportException::class);
        $this->expectExceptionMessage('is a snapshot but was published through the event API');
        $this->reporting()->event($snapshot);
    }

    #[Test]
    public function schema_version_and_occurred_at_are_taken_from_the_contract(): void
    {
        $at = new DateTimeImmutable('2026-01-02T03:04:05+00:00');

        $envelope = $this->reporting()->publish(new OrderSnapshot(schemaVersion: 4, occurredAt: $at));

        $this->assertSame(4, $envelope->schemaVersion);
        $this->assertSame('2026-01-02T03:04:05+00:00', $envelope->occurredAt->format(DATE_ATOM));
    }

    #[Test]
    public function occurred_at_defaults_to_publication_time(): void
    {
        $before = new DateTimeImmutable();
        $envelope = $this->reporting()->publish(new OrderSnapshot());

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $envelope->occurredAt->getTimestamp());
    }

    #[Test]
    public function snapshots_get_a_unique_event_id_per_publication_by_default(): void
    {
        $first = $this->reporting()->publish(new OrderSnapshot());
        $second = $this->reporting()->publish(new OrderSnapshot());

        $this->assertNotSame($first->eventId, $second->eventId);
    }

    #[Test]
    public function a_contract_may_supply_a_deterministic_idempotency_key(): void
    {
        $envelope = $this->reporting()->publish(new OrderSnapshot(idempotencyKey: 'order:paid:42'));

        $this->assertSame('order:paid:42', $envelope->eventId);
        $this->assertSame('order:paid:42', $this->transport->published()[0]->data['_reporting']['event_id']);
    }

    #[Test]
    public function correlation_id_is_generated_once_and_shared_across_publications(): void
    {
        $a = $this->reporting()->publish(new OrderSnapshot());
        $b = $this->reporting()->publish(new OrderSnapshot());

        $this->assertNotNull($a->correlationId);
        $this->assertSame($a->correlationId, $b->correlationId);
    }

    #[Test]
    public function correlation_id_comes_from_the_incoming_request_header(): void
    {
        $this->app->make(CorrelationId::class)->forget();
        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'POST', server: ['HTTP_X_CORRELATION_ID' => 'req-77']));

        $envelope = $this->reporting()->publish(new OrderSnapshot());

        $this->assertSame('req-77', $envelope->correlationId);
    }

    #[Test]
    public function explicit_correlation_id_wins(): void
    {
        $envelope = $this->reporting()->publish(new OrderSnapshot(), 'explicit-1');

        $this->assertSame('explicit-1', $envelope->correlationId);
        $this->assertSame('explicit-1', $this->transport->published()[0]->data['_reporting']['correlation_id']);
    }

    #[Test]
    public function correlation_id_is_null_when_generation_is_disabled_and_nothing_is_present(): void
    {
        config()->set('reporting.tracing.generate_if_missing', false);
        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(\SammyBoy\Reporting\Envelope\EnvelopeFactory::class);
        $this->app->forgetInstance(Reporting::class);

        $this->assertNull($this->reporting()->publish(new OrderSnapshot())->correlationId);
    }

    #[Test]
    public function rejects_malformed_contracts_before_touching_the_transport(): void
    {
        $cases = [
            new OrderSnapshot(eventName: 'Order Synced'),
            new OrderSnapshot(aggregateId: ''),
            new OrderSnapshot(aggregateType: ' '),
            new OrderSnapshot(schemaVersion: 0),
            new OrderSnapshot(payload: ['id' => 1, '_reporting' => []]),
        ];

        foreach ($cases as $case) {
            try {
                $this->reporting()->publish($case);
                $this->fail('Expected InvalidReportException');
            } catch (InvalidReportException) {
            }
        }

        $this->transport->assertNothingPublished();
    }

    #[Test]
    public function unroutable_events_fail_loudly(): void
    {
        config()->set('reporting.exchange', null);
        config()->set('reporting.exchanges', []);
        $this->app->forgetInstance(\SammyBoy\Reporting\Routing\ExchangeResolver::class);
        $this->app->forgetInstance(Reporting::class);

        $this->expectException(UnroutableReportException::class);
        $this->reporting()->publish(new OrderSnapshot());
    }

    #[Test]
    public function transport_failures_surface_as_report_publish_failed_with_the_envelope_attached(): void
    {
        Event::fake([ReportPublishing::class, ReportPublished::class, ReportPublishFailed::class]);
        $this->transport->failWith(new RuntimeException('broker down'));

        try {
            $this->reporting()->publish(new OrderSnapshot());
            $this->fail('Expected ReportPublishFailedException');
        } catch (ReportPublishFailedException $e) {
            $this->assertSame('order.snapshot.synced', $e->envelope->eventName);
            $this->assertSame('sales.events', $e->exchange);
            $this->assertSame('broker down', $e->getPrevious()?->getMessage());
        }

        Event::assertDispatched(ReportPublishing::class);
        Event::assertDispatched(ReportPublishFailed::class);
        Event::assertNotDispatched(ReportPublished::class);
    }

    #[Test]
    public function dispatches_lifecycle_events_on_success(): void
    {
        Event::fake([ReportPublishing::class, ReportPublished::class, ReportPublishFailed::class]);

        $envelope = $this->reporting()->publish(new OrderSnapshot());

        Event::assertDispatched(ReportPublishing::class, fn ($e) => $e->envelope === $envelope && $e->exchange === 'sales.events');
        Event::assertDispatched(ReportPublished::class, fn ($e) => $e->envelope === $envelope && $e->durationMs >= 0);
        Event::assertNotDispatched(ReportPublishFailed::class);
    }

    #[Test]
    public function publishing_inside_a_transaction_is_deferred_until_commit(): void
    {
        DB::transaction(function () {
            $this->reporting()->publish(new OrderSnapshot());
            $this->transport->assertNothingPublished();
        });

        $this->transport->assertPublishedTimes('order.snapshot.synced', 1);
    }

    #[Test]
    public function publishing_inside_a_rolled_back_transaction_is_discarded(): void
    {
        try {
            DB::transaction(function () {
                $this->reporting()->publish(new OrderSnapshot());
                throw new RuntimeException('business rule failed');
            });
        } catch (RuntimeException) {
        }

        $this->transport->assertNothingPublished();
    }

    #[Test]
    public function nested_transactions_publish_only_once_the_outermost_commits(): void
    {
        DB::transaction(function () {
            DB::transaction(function () {
                $this->reporting()->publish(new OrderSnapshot());
            });
            $this->transport->assertNothingPublished();
        });

        $this->transport->assertPublishedTimes('order.snapshot.synced', 1);
    }

    #[Test]
    public function after_commit_can_be_disabled(): void
    {
        config()->set('reporting.publish.after_commit', false);
        $this->app->forgetInstance(Reporting::class);

        DB::transaction(function () {
            $this->reporting()->publish(new OrderSnapshot());
            $this->transport->assertPublishedTimes('order.snapshot.synced', 1);
        });
    }
}
