# sammy-boy/laravel-reporting

Reporting publication layer for Laravel microservices. The package owns **how** a reporting message is shaped and published; it does not own what any particular service reports, and it does not own the message bus.

```
Producer service
  └─ Reporting::snapshot($contract) / ::event($contract)      ← this package
       └─ ReportEnvelope  (event_id, kind, schema_version, aggregate, correlation_id, payload)
            └─ uzapoint/eventbus-core  RabbitMQService::publish()  ← transport, confirms, {meta,data} envelope
                 └─ RabbitMQ topic exchange
                      └─ Consumer service  eventbus:consume → handler → IncomingReport::from($data)
```

## What it does

- Accepts any object implementing `SammyBoy\Reporting\Contracts\Reportable` and publishes it as a reporting message with a canonical envelope: `event_id`, `event_name`, `kind` (snapshot or event), `schema_version`, `occurred_at`, `aggregate_type`, `aggregate_id`, `source`, `correlation_id`, `actor_id`, `payload`.
- Resolves the destination exchange from configuration, with the contract's `eventName()` as routing key.
- Propagates correlation ids: from the incoming request header named by eventbus-core's `eventbus.tracing.header`, or generated once per request/job when absent.
- Defers publication to the commit of the surrounding database transaction and discards it on rollback.
- Dispatches `ReportPublishing`, `ReportPublished` and `ReportPublishFailed` Laravel events for monitoring.
- Ships a recording fake (`Reporting::fake()`) with assertions for producer tests, and `IncomingReport` for consumers to decode either generation of message.

## What it does not do

- It does not connect to RabbitMQ, declare topology, retry, dead-letter or deduplicate. All of that is `uzapoint/eventbus-core`, which this package depends on and never bypasses. There is no php-amqplib code here.
- It does not define any business payload. UzaPoint's payloads live in `uzapoint/reporting-contracts`.
- It does not consume messages or project them. Consumers keep using `eventbus:consume` and their own handlers.
- It does not persist an outbox. See *Transactions* for what it does instead and why.

## Installation

```bash
composer require sammy-boy/laravel-reporting
php artisan vendor:publish --tag=reporting-config
```

The service provider and the `Reporting` facade are auto-discovered. `uzapoint/eventbus-core` must be configured in the host service as it already is for ordinary cross-service events (`config/eventbus.php`, `queue.connections.rabbitmq.*`).

## Configuration

`config/reporting.php`:

| Key | Purpose |
| --- | --- |
| `exchange` | Default exchange for every report this service publishes (`sales.events`, `accounts.events`, …). |
| `exchanges` | Per-event overrides: exact event names or trailing-wildcard patterns (`invoice.*`). An event that resolves to no exchange throws `UnroutableReportException`. |
| `envelope.metadata_key` | The key inside `data` that carries the reporting metadata block. Default `_reporting`. Payloads may not use it. |
| `envelope.format` | `flat` or `wrapped` (see *Wire format*). Default `flat`. |
| `envelope.formats` | Per-event overrides for the format, same matching rules as `exchanges`. |
| `publish.after_commit` | Defer publication to the enclosing transaction's commit. Default `true`. |
| `publish.connection` | Database connection whose transaction state is observed. `null` is the default connection. |
| `tracing.generate_if_missing` | Mint a UUID correlation id when no header is present. Default `true`. |

A producer with one exchange needs one line:

```php
// sales-ms  config/reporting.php
'exchange' => 'sales.events',
```

A producer whose consumers expect the accounts convention:

```php
// accounts-ms  config/reporting.php
'exchange' => 'accounts.events',
'envelope' => ['format' => 'wrapped'],
```

## Implementing a contract

A contract is any class implementing `Reportable`. The methods are instance methods so one class can drive several lifecycle routing keys from its own state.

```php
use SammyBoy\Reporting\Contracts\Reportable;
use SammyBoy\Reporting\Envelope\ReportKind;

final class OrderSnapshot implements Reportable
{
    public function __construct(private Order $order) {}

    public function eventName(): string      { return 'order.snapshot.synced'; }
    public function kind(): ReportKind       { return ReportKind::Snapshot; }
    public function schemaVersion(): int     { return 1; }
    public function aggregateType(): string  { return 'order'; }
    public function aggregateId(): string|int { return $this->order->id; }
    public function occurredAt(): ?DateTimeInterface { return $this->order->placed_at; }
    public function idempotencyKey(): ?string { return null; }
    public function actorId(): int|string|null { return $this->order->user_id; }
    public function toReportPayload(): array { return [/* wire fields */]; }
}
```

`uzapoint/reporting-contracts` provides a `ReportData` base class on top of spatie/laravel-data that supplies the optional methods and `toReportPayload()`.

## Publishing

```php
use SammyBoy\Reporting\Reporting;

final class SaleService
{
    public function __construct(private Reporting $reporting) {}

    public function complete(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            // ...business writes...

            $this->reporting->snapshot(SaleReportData::fromSale($sale));
        });
    }
}
```

`snapshot()` and `event()` assert the contract's declared `kind()` and throw `InvalidReportException` on mismatch; `publish()` trusts it. All three return the `ReportEnvelope` that was, or will be, sent, so `event_id` and `correlation_id` can be logged by the caller. An explicit correlation id can be passed as the second argument.

The `Reporting` facade exposes the same three methods.

## Snapshot versus event

`ReportKind::Snapshot` means "this is the aggregate's current reportable state"; the consumer upserts, and every publication must be processed, so the envelope gets a fresh `event_id` each time unless the contract supplies one. `ReportKind::Event` means "this happened"; the consumer applies a delta or appends, and the contract normally supplies a deterministic `idempotencyKey()` such as `expense:approved:1201` so a redelivery is suppressed. The distinction is carried in the metadata block as `kind` and is never inferred from the routing key.

## Wire format

eventbus-core publishes `{meta: {id, type, source, timestamp}, data: {...}}` and delivers only `data` to handlers. Reporting therefore places its metadata inside `data`, under `envelope.metadata_key`, and uses the report's `event_id` as eventbus-core's `idempotency_key` so `meta.id` and the metadata `event_id` are the same value.

`flat` (default) keeps payload fields at the top level, which is what handlers that read `$data['field']` expect:

```json
{
  "id": 9001, "business_id": 12, "grand_total": 2000.5, "items": [...],
  "_reporting": {
    "event_id": "9d2a…", "event_name": "sale.item.synced", "kind": "snapshot",
    "schema_version": 1, "occurred_at": "2026-10-13T13:45:00+03:00",
    "aggregate_type": "sale", "aggregate_id": "9001", "source": "sales-ms",
    "correlation_id": "3f1c…", "actor_id": 3
  }
}
```

`wrapped` reproduces the `{auth_user_id, payload}` convention whose consumers destructure `(?int $auth_user_id, array $payload)` through eventbus-core's constructor mapping:

```json
{ "auth_user_id": 3, "payload": { "expense_id": 1201, "...": "...", "_reporting": { "...": "..." } } }
```

Routing keys are never rewritten by this package. Whatever `eventName()` returns is what the bus sees.

## Schema versioning

Every envelope carries the contract's `schemaVersion()`. A consumer reads it from `IncomingReport::schemaVersion()` and branches; `0` means the payload was published without this package (a legacy, uncontracted message). Bump the version when a field is removed, renamed or changes meaning. Adding an optional field is not a version bump: `IncomingReport` and the contracts ignore unknown keys.

## Consuming

Handlers keep eventbus-core's calling convention. `IncomingReport` normalises every generation of message a routing key can carry:

```php
use SammyBoy\Reporting\Envelope\IncomingReport;

public function handle(array $data = []): void
{
    $report = IncomingReport::from($data);

    $sale = match ($report->schemaVersion()) {
        0 => $this->fromLegacyArray($report->payload),   // until every producer is migrated
        default => SaleReportData::from($report->payload),
    };

    Log::info('sale projected', ['correlation_id' => $report->correlationId(), 'event_id' => $report->envelope?->eventId]);
}
```

`IncomingReport::from()` accepts the flat format, the wrapped format, the full `{meta, data}` bus envelope, and legacy payloads (including the legacy `{auth_user_id, payload}` wrapper). `payload` never contains the metadata key.

## Idempotency

eventbus-core guards duplicates with `SET NX` on `eventbus.idempotency.redis_prefix . meta.id`, and this package makes `meta.id` the report's `event_id`. Consequences to design around:

- The guard is scoped by the consuming service's `redis_prefix`. Two services consuming the same event must use different prefixes, otherwise the first one to process it suppresses the other. Namespace the prefix per service (`event:processed:reports:`), as reports-ms already does.
- The guard is keyed on `event_id` only, not on handler. Within one service, one `event_id` is processed once regardless of how many queues it reaches.
- A deterministic `idempotencyKey()` means "this fact, once": a retried publish of the same expense approval is processed once. A snapshot must not use one, because every re-sync is a new fact about the aggregate.
- The guard marks the id processed before the handler runs (see *Known eventbus-core behaviour*).

## Correlation ids

`CorrelationId` is a scoped singleton. On first use it takes the explicitly `set()` value, else the incoming request's `X-Correlation-ID` header (the name comes from `eventbus.tracing.header`), else a generated UUID when `tracing.generate_if_missing` is on. The value is pinned for the request or job, so every report published while handling one unit of work shares it. A consumer that republishes under the id it received calls `app(CorrelationId::class)->set($report->correlationId())` first.

eventbus-core v1.0.x does not emit a `meta.correlation_id`; the id travels in the metadata block.

## Transactions

With `publish.after_commit` on, a publish requested inside `DB::transaction()` is registered with the connection's transaction manager and sent only after the outermost commit; a rollback discards it. Outside a transaction it is sent immediately. The envelope is built and validated eagerly either way, so contract errors surface at the call site.

This closes the "message describes data that never committed" failure. It does not close "commit succeeded, broker publish failed": that failure surfaces as `ReportPublishFailedException` thrown out of `DB::transaction()` after the commit, with the envelope attached, and `ReportPublishFailed` is dispatched. Handling it (log, alert, re-publish from the aggregate) is the producer's decision. A durable outbox table and relay worker would close it fully; that is deliberately not built here because no UzaPoint service has an outbox today and eventbus-core has no replay, so it would be a new piece of infrastructure in every producer rather than an extraction of something that exists.

## Monitoring

Three events are dispatched around every send: `ReportPublishing` (envelope, exchange), `ReportPublished` (envelope, exchange, duration in ms), `ReportPublishFailed` (envelope, exchange, exception). Listen to them for logging or metrics; the failure is re-thrown after the event.

## Exceptions

All extend `ReportingException`.

| Exception | When |
| --- | --- |
| `InvalidReportException` | Bad event name, empty aggregate id/type, schema version below 1, payload using the metadata key, or kind mismatch in `snapshot()`/`event()`. Always a producer bug. |
| `UnroutableReportException` | No exchange resolves for the event name. |
| `ReportPublishFailedException` | eventbus-core threw. Carries `envelope`, `exchange` and the original exception as previous. |

## Testing

```php
use SammyBoy\Reporting\Facades\Reporting;

$fake = Reporting::fake();

$service->complete($sale);

$fake->assertPublished('sale.item.synced', fn ($envelope, $exchange, $data) =>
    $exchange === 'sales.events' && $data['id'] === $sale->id && $envelope->schemaVersion === 1
);
$fake->assertPublishedTimes('sale.item.synced', 1);
$fake->assertNotPublished('sale.payment.recorded');
$fake->failWith(new RuntimeException('broker down'));   // subsequent sends throw ReportPublishFailedException
```

`published()` returns `PublishedReport` objects with the envelope, exchange and the exact `data` array eventbus-core would have received.

Run the package's own suite with `composer test`. It contains unit tests for the envelope and routing, Laravel feature tests for the provider, publication, transactions, correlation and failure paths, and an integration suite that drives the real `RabbitMQService::publish()` with a mocked AMQP channel and feeds the resulting body through the real `EventProcessor` into handlers using both consumer conventions.

## Migration strategy

1. Install the package in the producer and set `reporting.exchange`. Nothing changes yet.
2. Build or install the contract for one routing key. Publish it through `Reporting::snapshot()` instead of calling `RabbitMQService::publish()` by hand. The routing key, exchange and payload field names stay the same, so the existing consumer handler keeps working without deployment.
3. In the consumer, wrap the handler's input in `IncomingReport::from()` and hydrate the contract when `isContracted()`. Keep the legacy branch until every producer of that key is migrated.
4. Verify parity with a side-by-side projection test (legacy array versus contracted payload producing identical documents).
5. Repeat per routing key. Remove legacy branches and hand-rolled publishers last.

## Known eventbus-core behaviour (v1.0.9)

Observed while integrating; they are eventbus-core's to change, and this package does not work around them silently.

- `RabbitMQService::publish()` reads only `idempotency_key` from `$headers`; `correlation_id` passed there is dropped, and nothing is written to AMQP `application_headers`.
- `EventProcessor` marks `meta.id` as processed before invoking the handler. If the handler throws, the consumer's retry republish is then discarded as a duplicate.
- The retry republish targets the default exchange with the original routing key, which only reaches a queue literally named after that key.
- `idempotency.enabled`, `publisher.*` and `tracing.*` configuration keys are not read.
- JSON encoding omits `JSON_PRESERVE_ZERO_FRACTION`, so whole-number floats arrive as integers.

## License

MIT.
