<?php

declare(strict_types=1);

namespace SammyBoy\Reporting;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use SammyBoy\Reporting\Contracts\Reportable;
use SammyBoy\Reporting\Contracts\ReportTransport;
use SammyBoy\Reporting\Envelope\EnvelopeFactory;
use SammyBoy\Reporting\Envelope\ReportEnvelope;
use SammyBoy\Reporting\Envelope\ReportKind;
use SammyBoy\Reporting\Events\ReportPublished;
use SammyBoy\Reporting\Events\ReportPublishFailed;
use SammyBoy\Reporting\Events\ReportPublishing;
use SammyBoy\Reporting\Exceptions\InvalidReportException;
use SammyBoy\Reporting\Routing\EnvelopeFormatResolver;
use SammyBoy\Reporting\Routing\ExchangeResolver;
use Throwable;

/**
 * The producer-facing API. Three verbs:
 *
 *   publish()  — publish any Reportable, trusting its declared kind
 *   snapshot() — publish, asserting the contract is a snapshot
 *   event()    — publish, asserting the contract is an event
 *
 * Each returns the ReportEnvelope that was (or will be) sent, so callers can
 * log event_id and correlation_id without reaching into the transport.
 *
 * When publish.after_commit is on and the default connection is inside a
 * transaction, sending is deferred to that transaction's commit and dropped on
 * rollback. The envelope is still built — and validated — eagerly.
 */
final class Reporting
{
    public function __construct(
        private readonly EnvelopeFactory $envelopes,
        private readonly ExchangeResolver $exchanges,
        private readonly EnvelopeFormatResolver $formats,
        private readonly ReportTransport $transport,
        private readonly Dispatcher $events,
        private readonly ConnectionResolverInterface $connections,
        private readonly string $metadataKey,
        private readonly bool $afterCommit,
        private readonly ?string $connectionName,
    ) {}

    public function publish(Reportable $report, ?string $correlationId = null): ReportEnvelope
    {
        $envelope = $this->envelopes->make($report, $correlationId);
        $exchange = $this->exchanges->resolve($envelope->eventName);
        $format = $this->formats->resolve($envelope->eventName);

        // Framing validates the reserved metadata key, so do it before deferring.
        $data = $envelope->toTransportData($format, $this->metadataKey);

        $send = fn () => $this->send($envelope, $exchange, $data);

        $connection = $this->afterCommit
            ? $this->connections->connection($this->connectionName)
            : null;

        // ConnectionInterface does not declare afterCommit(); every Illuminate
        // connection implements it, but a custom driver might not.
        if ($connection !== null && method_exists($connection, 'afterCommit')) {
            $connection->afterCommit($send);
        } else {
            $send();
        }

        return $envelope;
    }

    public function snapshot(Reportable $report, ?string $correlationId = null): ReportEnvelope
    {
        $this->assertKind($report, ReportKind::Snapshot);

        return $this->publish($report, $correlationId);
    }

    public function event(Reportable $report, ?string $correlationId = null): ReportEnvelope
    {
        $this->assertKind($report, ReportKind::Event);

        return $this->publish($report, $correlationId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(ReportEnvelope $envelope, string $exchange, array $data): void
    {
        $this->events->dispatch(new ReportPublishing($envelope, $exchange));

        $started = hrtime(true);

        try {
            $this->transport->send($envelope, $exchange, $data);
        } catch (Throwable $e) {
            $this->events->dispatch(new ReportPublishFailed($envelope, $exchange, $e));

            throw $e;
        }

        $this->events->dispatch(new ReportPublished(
            $envelope,
            $exchange,
            (hrtime(true) - $started) / 1_000_000,
        ));
    }

    private function assertKind(Reportable $report, ReportKind $expected): void
    {
        if ($report->kind() !== $expected) {
            throw InvalidReportException::kindMismatch($report::class, $expected, $report->kind());
        }
    }
}
