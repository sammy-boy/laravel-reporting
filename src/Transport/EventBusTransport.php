<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Transport;

use SammyBoy\Reporting\Contracts\ReportTransport;
use SammyBoy\Reporting\Envelope\ReportEnvelope;
use SammyBoy\Reporting\Exceptions\ReportPublishFailedException;
use Throwable;
use Uzapoint\EventBus\RabbitMQService;

/**
 * The only production transport: a thin adapter over eventbus-core's
 * RabbitMQService. Connection management, publisher confirms, persistence and
 * the outer {meta, data} envelope are all eventbus-core's; this class only
 * maps the reporting envelope onto its publish() signature.
 *
 * RabbitMQService::publish() reads exactly one key from $headers —
 * idempotency_key — and uses it as meta.id. Passing the report's eventId there
 * makes eventbus-core's consumer-side duplicate guard operate on the same
 * identity the reporting layer reasons about.
 */
final class EventBusTransport implements ReportTransport
{
    public function __construct(private readonly RabbitMQService $bus) {}

    public function send(ReportEnvelope $envelope, string $exchange, array $data): void
    {
        try {
            $this->bus->publish(
                exchange: $exchange,
                routingKey: $envelope->eventName,
                data: $data,
                headers: ['idempotency_key' => $envelope->eventId],
            );
        } catch (Throwable $e) {
            throw ReportPublishFailedException::wrap($envelope, $exchange, $e);
        }
    }
}
