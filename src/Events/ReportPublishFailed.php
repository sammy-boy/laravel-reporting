<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Events;

use SammyBoy\Reporting\Envelope\ReportEnvelope;
use Throwable;

/**
 * Dispatched when the transport throws. The exception is re-thrown to the
 * caller afterwards; this event exists so monitoring never depends on every
 * caller remembering to log.
 */
final readonly class ReportPublishFailed
{
    public function __construct(
        public ReportEnvelope $envelope,
        public string $exchange,
        public Throwable $exception,
    ) {}
}
