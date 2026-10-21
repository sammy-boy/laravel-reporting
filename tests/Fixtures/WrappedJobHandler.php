<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Fixtures;

use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Job-style handler with the exact constructor the Reports MS accounts
 * handler (HandleExpenseCreated) uses, so eventbus-core's constructor
 * mapping is exercised: auth_user_id <- data.auth_user_id, payload <- data.
 */
final class WrappedJobHandler
{
    use Dispatchable;

    /** @var list<array{auth_user_id: ?int, payload: array<string, mixed>}> */
    public static array $received = [];

    public function __construct(
        public ?int $auth_user_id,
        public array $payload,
    ) {}

    public function handle(): void
    {
        self::$received[] = ['auth_user_id' => $this->auth_user_id, 'payload' => $this->payload];
    }
}
