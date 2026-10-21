<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Events;

use SammyBoy\Reporting\Envelope\ReportEnvelope;

/**
 * Dispatched immediately before the transport is invoked (after any
 * after-commit deferral). Listen to log, meter or trace outgoing reports.
 */
final readonly class ReportPublishing
{
    public function __construct(
        public ReportEnvelope $envelope,
        public string $exchange,
    ) {}
}
