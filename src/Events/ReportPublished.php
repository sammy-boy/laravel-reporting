<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Events;

use SammyBoy\Reporting\Envelope\ReportEnvelope;

/**
 * Dispatched after the transport accepted the message (eventbus-core waits for
 * the broker's publisher confirm before returning).
 */
final readonly class ReportPublished
{
    public function __construct(
        public ReportEnvelope $envelope,
        public string $exchange,
        public float $durationMs,
    ) {}
}
