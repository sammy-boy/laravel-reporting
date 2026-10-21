<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Exceptions;

/**
 * No exchange could be resolved for an event name. Fix reporting.exchange or
 * add an entry to reporting.exchanges in the publishing service.
 */
final class UnroutableReportException extends ReportingException
{
    public static function forEvent(string $eventName): self
    {
        return new self(sprintf(
            'No exchange configured for "%s". Set reporting.exchange or add a reporting.exchanges entry.',
            $eventName,
        ));
    }
}
