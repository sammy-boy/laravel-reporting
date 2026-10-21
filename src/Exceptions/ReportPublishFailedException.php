<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Exceptions;

use SammyBoy\Reporting\Envelope\ReportEnvelope;
use Throwable;

/**
 * eventbus-core failed to publish. The original transport exception is the
 * previous exception; the envelope is attached so callers can log or persist
 * exactly what was lost.
 */
final class ReportPublishFailedException extends ReportingException
{
    private function __construct(
        public readonly ReportEnvelope $envelope,
        public readonly string $exchange,
        string $message,
        Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function wrap(ReportEnvelope $envelope, string $exchange, Throwable $previous): self
    {
        return new self(
            $envelope,
            $exchange,
            sprintf(
                'Failed to publish "%s" (event_id %s) to exchange "%s": %s',
                $envelope->eventName,
                $envelope->eventId,
                $exchange,
                $previous->getMessage(),
            ),
            $previous,
        );
    }
}
