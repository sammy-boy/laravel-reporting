<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Routing;

use SammyBoy\Reporting\Exceptions\UnroutableReportException;

final class ExchangeResolver
{
    /**
     * @param array<string, string> $overrides event name or "prefix.*" => exchange
     */
    public function __construct(
        private readonly ?string $default,
        private readonly array $overrides = [],
    ) {}

    public function resolve(string $eventName): string
    {
        $exchange = EventNameMatcher::resolve($eventName, $this->overrides, $this->default);

        if ($exchange === null || $exchange === '') {
            throw UnroutableReportException::forEvent($eventName);
        }

        return $exchange;
    }
}
