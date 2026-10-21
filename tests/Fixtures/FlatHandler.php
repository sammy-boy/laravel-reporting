<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Fixtures;

/**
 * Service-style handler with the exact calling convention of the Reports MS
 * sales handlers (ProcessSaleSnapshot & co): app($handler)->handle($data).
 */
final class FlatHandler
{
    /** @var list<array<string, mixed>> */
    public static array $received = [];

    public function handle(array $data = []): void
    {
        self::$received[] = $data;
    }
}
