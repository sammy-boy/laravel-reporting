<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Routing;

/**
 * Resolves a per-event setting from a map of exact names and trailing-wildcard
 * patterns. Shared by exchange and envelope-format resolution so both config
 * keys obey identical matching rules:
 *
 *   1. exact match               "invoice.snapshot.synced"
 *   2. longest matching prefix   "invoice.*", then "*" is not supported —
 *                                use the default instead
 *   3. default
 *
 * @template T
 */
final class EventNameMatcher
{
    /**
     * @param array<string, T> $map
     * @param T|null $default
     * @return T|null
     */
    public static function resolve(string $eventName, array $map, mixed $default = null): mixed
    {
        if (array_key_exists($eventName, $map)) {
            return $map[$eventName];
        }

        $best = null;
        $bestLength = -1;

        foreach ($map as $pattern => $value) {
            if (! str_ends_with($pattern, '.*')) {
                continue;
            }

            $prefix = substr($pattern, 0, -1); // keep the trailing dot

            if (str_starts_with($eventName, $prefix) && strlen($prefix) > $bestLength) {
                $best = $value;
                $bestLength = strlen($prefix);
            }
        }

        return $bestLength >= 0 ? $best : $default;
    }
}
