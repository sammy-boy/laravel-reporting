<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Testing;

use SammyBoy\Reporting\Envelope\ReportEnvelope;

final readonly class PublishedReport
{
    /**
     * @param array<string, mixed> $data the framed `data` exactly as eventbus-core would receive it
     */
    public function __construct(
        public ReportEnvelope $envelope,
        public string $exchange,
        public array $data,
    ) {}
}
