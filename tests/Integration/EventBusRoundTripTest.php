<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Integration;

use Illuminate\Support\Facades\Redis;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use SammyBoy\Reporting\Envelope\IncomingReport;
use SammyBoy\Reporting\Envelope\ReportKind;
use SammyBoy\Reporting\Exceptions\ReportPublishFailedException;
use SammyBoy\Reporting\Reporting;
use SammyBoy\Reporting\Tests\Fixtures\CapturingRabbitMQService;
use SammyBoy\Reporting\Tests\Fixtures\FlatHandler;
use SammyBoy\Reporting\Tests\Fixtures\OrderSnapshot;
use SammyBoy\Reporting\Tests\Fixtures\WrappedJobHandler;
use SammyBoy\Reporting\Tests\TestCase;
use SammyBoy\Reporting\Transport\EventBusTransport;
use Uzapoint\EventBus\EventProcessor;
use Uzapoint\EventBus\EventRegistry;
use Uzapoint\EventBus\RabbitMQService;

/**
 * Producer -> Reporting -> EventBusTransport -> RabbitMQService::publish()
 * -> AMQP body -> EventProcessor::process() -> handler, with only the socket
 * replaced. This is the boundary that matters for the migration: the bytes
 * the Reports MS consumer receives.
 */
final class EventBusRoundTripTest extends TestCase
{
    private CapturingRabbitMQService $bus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new CapturingRabbitMQService();
        $this->app->instance(RabbitMQService::class, $this->bus);
        $this->app->forgetInstance(EventBusTransport::class);
        $this->app->forgetInstance(Reporting::class);

        FlatHandler::$received = [];
        WrappedJobHandler::$received = [];

        // eventbus-core's duplicate guard uses the Redis facade; a fresh fake
        // store per test makes the guard real without a Redis server.
        Redis::swap(new class {
            public array $keys = [];

            public function set(string $key, mixed $value, ...$options): ?string
            {
                if (in_array('NX', $options, true) && array_key_exists($key, $this->keys)) {
                    return null;
                }
                $this->keys[$key] = $value;

                return 'OK';
            }
        });
    }

    private function reporting(): Reporting
    {
        return $this->app->make(Reporting::class);
    }

    private function consume(string $handler, ?array $body = null): void
    {
        $body ??= $this->bus->lastBody();
        $last = $this->bus->published[array_key_last($this->bus->published)];

        $registry = new EventRegistry([$last['routing_key'] => $handler]);
        $message = new AMQPMessage(json_encode($body, JSON_THROW_ON_ERROR));
        $message->setDeliveryInfo(1, false, $last['exchange'], $last['routing_key']);

        (new EventProcessor($registry))->process($message);
    }

    #[Test]
    public function the_real_eventbus_publisher_receives_exchange_routing_key_and_idempotency_key(): void
    {
        $envelope = $this->reporting()->publish(new OrderSnapshot());

        $this->assertCount(1, $this->bus->published);
        $this->assertSame(1, $this->bus->exchangeDeclarations);
        $this->assertSame(1, $this->bus->confirmWaits, 'publisher confirms are eventbus-core\'s and must be awaited');

        $published = $this->bus->published[0];
        $this->assertSame('sales.events', $published['exchange']);
        $this->assertSame('order.snapshot.synced', $published['routing_key']);
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $published['message']->get('delivery_mode'));

        $body = $this->bus->lastBody();
        $this->assertSame($envelope->eventId, $body['meta']['id'], 'report event_id must become the bus idempotency id');
        $this->assertSame('order.snapshot.synced', $body['meta']['type']);
        $this->assertSame('sales-service', $body['meta']['source']);
        $this->assertSame(42, $body['data']['id']);
        $this->assertSame($envelope->metadata(), $body['data']['_reporting']);
    }

    #[Test]
    public function flat_snapshot_reaches_a_sales_style_handler_with_legacy_fields_intact(): void
    {
        $this->reporting()->publish(new OrderSnapshot());
        $this->consume(FlatHandler::class);

        $this->assertCount(1, FlatHandler::$received);
        $data = FlatHandler::$received[0];

        // What a legacy handler reads, unchanged.
        $this->assertSame(42, $data['id']);
        $this->assertSame(150.5, $data['total']);
        $this->assertSame([['sku' => 'A', 'qty' => 2]], $data['items']);

        // What a migrated handler reads.
        $incoming = IncomingReport::from($data);
        $this->assertTrue($incoming->isContracted());
        $this->assertSame(1, $incoming->schemaVersion());
        $this->assertSame(ReportKind::Snapshot, $incoming->envelope->kind);
        $this->assertSame('order', $incoming->envelope->aggregateType);
        $this->assertSame(['id' => 42, 'total' => 150.5, 'items' => [['sku' => 'A', 'qty' => 2]]], $incoming->payload);
    }

    #[Test]
    public function wrapped_event_reaches_an_accounts_style_job_through_constructor_mapping(): void
    {
        config()->set('reporting.envelope.formats', ['order.*' => 'wrapped']);
        $this->app->forgetInstance(\SammyBoy\Reporting\Routing\EnvelopeFormatResolver::class);
        $this->app->forgetInstance(Reporting::class);

        $this->reporting()->event(new OrderSnapshot(
            eventName: 'order.paid',
            kind: ReportKind::Event,
            actorId: 7,
            idempotencyKey: 'order:paid:42',
            payload: ['order_id' => 42, 'amount' => 150.5],
        ));
        $this->consume(WrappedJobHandler::class);

        $this->assertCount(1, WrappedJobHandler::$received);
        ['auth_user_id' => $actor, 'payload' => $payload] = WrappedJobHandler::$received[0];

        $this->assertSame(7, $actor);
        $this->assertSame(42, $payload['payload']['order_id'], 'HandleExpenseCreated-style jobs read $payload["payload"]');

        $incoming = IncomingReport::from($payload);
        $this->assertTrue($incoming->isContracted());
        $this->assertSame('order:paid:42', $incoming->envelope->eventId);
        $this->assertSame(['order_id' => 42, 'amount' => 150.5], $incoming->payload);
    }

    #[Test]
    public function eventbus_duplicate_guard_operates_on_the_report_event_id(): void
    {
        $this->reporting()->publish(new OrderSnapshot(idempotencyKey: 'order:snapshot:42:v1'));
        $this->consume(FlatHandler::class);
        $this->consume(FlatHandler::class);

        $this->assertCount(1, FlatHandler::$received, 'second delivery of the same event_id must be suppressed by eventbus-core');
        $this->assertArrayHasKey('event:processed:order:snapshot:42:v1', Redis::getFacadeRoot()->keys);
    }

    #[Test]
    public function two_snapshots_of_the_same_aggregate_are_both_processed(): void
    {
        $this->reporting()->publish(new OrderSnapshot(payload: ['id' => 42, 'total' => 100.25]));
        $this->consume(FlatHandler::class);
        $this->reporting()->publish(new OrderSnapshot(payload: ['id' => 42, 'total' => 120.75]));
        $this->consume(FlatHandler::class);

        $this->assertCount(2, FlatHandler::$received);
        $this->assertSame(120.75, FlatHandler::$received[1]['total']);
    }

    #[Test]
    public function legacy_hand_built_messages_still_decode_as_uncontracted(): void
    {
        // Exactly what today's producers send: no metadata block at all.
        $legacy = ['meta' => ['id' => 'x', 'type' => 'order.snapshot.synced'], 'data' => ['id' => 42, 'total' => 99.5]];

        $registry = new EventRegistry(['order.snapshot.synced' => FlatHandler::class]);
        $message = new AMQPMessage(json_encode($legacy, JSON_THROW_ON_ERROR));
        $message->setDeliveryInfo(1, false, 'sales.events', 'order.snapshot.synced');
        (new EventProcessor($registry))->process($message);

        $incoming = IncomingReport::from(FlatHandler::$received[0]);
        $this->assertFalse($incoming->isContracted());
        $this->assertSame(0, $incoming->schemaVersion());
        $this->assertSame(['id' => 42, 'total' => 99.5], $incoming->payload);
    }

    #[Test]
    public function broker_failures_are_wrapped_with_the_envelope(): void
    {
        $failing = new class extends RabbitMQService {
            public function publish(string $exchange, string $routingKey, array $data, array $headers = []): void
            {
                throw new \PhpAmqpLib\Exception\AMQPConnectionClosedException('Broken pipe');
            }
        };
        $this->app->instance(RabbitMQService::class, $failing);
        $this->app->forgetInstance(EventBusTransport::class);
        $this->app->forgetInstance(Reporting::class);

        try {
            $this->reporting()->publish(new OrderSnapshot());
            $this->fail('expected failure');
        } catch (ReportPublishFailedException $e) {
            $this->assertSame('order.snapshot.synced', $e->envelope->eventName);
            $this->assertInstanceOf(\PhpAmqpLib\Exception\AMQPConnectionClosedException::class, $e->getPrevious());
        }
    }
}
