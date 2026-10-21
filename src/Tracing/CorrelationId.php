<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tracing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The correlation id for the current unit of work.
 *
 * Resolution order: explicitly set() value, the incoming HTTP request header
 * (named by eventbus-core's tracing.header so both packages agree), then a
 * freshly generated UUID when configured to do so. Once resolved the value is
 * pinned for the lifecycle, so every report published while handling one
 * request or one job shares the same id.
 *
 * Bound as a scoped singleton: Laravel resets scoped instances per request
 * (Octane) and per queued job, which is exactly the lifetime a correlation id
 * should have.
 */
final class CorrelationId
{
    private ?string $current = null;

    public function __construct(
        private readonly Container $container,
        private readonly string $header,
        private readonly bool $generateIfMissing,
    ) {}

    public function header(): string
    {
        return $this->header;
    }

    /**
     * Pin the id for this lifecycle — e.g. a consumer re-publishing under the
     * id it received, or a job carrying the id of the request that queued it.
     */
    public function set(?string $id): void
    {
        $this->current = ($id === null || $id === '') ? null : $id;
    }

    public function current(): ?string
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $fromRequest = $this->fromRequest();

        if ($fromRequest !== null) {
            return $this->current = $fromRequest;
        }

        if ($this->generateIfMissing) {
            return $this->current = (string) Str::uuid();
        }

        return null;
    }

    public function forget(): void
    {
        $this->current = null;
    }

    private function fromRequest(): ?string
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        if (! $request instanceof Request) {
            return null;
        }

        $value = $request->header($this->header);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
