<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Routing;

use SammyBoy\Reporting\Envelope\EnvelopeFormat;

final class EnvelopeFormatResolver
{
    /**
     * @param array<string, string> $overrides event name or "prefix.*" => format value
     */
    public function __construct(
        private readonly EnvelopeFormat $default,
        private readonly array $overrides = [],
    ) {}

    public function resolve(string $eventName): EnvelopeFormat
    {
        $value = EventNameMatcher::resolve($eventName, $this->overrides);

        return $value === null ? $this->default : EnvelopeFormat::from((string) $value);
    }
}
