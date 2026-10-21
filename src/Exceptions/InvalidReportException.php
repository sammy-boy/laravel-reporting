<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Exceptions;

use SammyBoy\Reporting\Envelope\ReportKind;

/**
 * The Reportable handed to the publisher is malformed. Always a programming
 * error in the producer, never a transport condition.
 */
final class InvalidReportException extends ReportingException
{
    public static function invalidEventName(string $class, string $eventName): self
    {
        return new self(sprintf(
            '%s declares event name "%s"; expected lower-case dotted segments such as "sale.item.synced".',
            $class,
            $eventName,
        ));
    }

    public static function emptyAggregateId(string $class): self
    {
        return new self(sprintf('%s returned an empty aggregateId(); reports must identify their aggregate.', $class));
    }

    public static function emptyAggregateType(string $class): self
    {
        return new self(sprintf('%s returned an empty aggregateType().', $class));
    }

    public static function invalidSchemaVersion(string $class, int $version): self
    {
        return new self(sprintf('%s declares schema version %d; versions start at 1.', $class, $version));
    }

    public static function reservedKey(string $eventName, string $key): self
    {
        return new self(sprintf(
            'Payload for "%s" contains "%s", which is reserved for reporting metadata (reporting.envelope.metadata_key).',
            $eventName,
            $key,
        ));
    }

    public static function kindMismatch(string $class, ReportKind $expected, ReportKind $actual): self
    {
        return new self(sprintf(
            '%s is a %s but was published through the %s API.',
            $class,
            $actual->value,
            $expected->value,
        ));
    }
}
