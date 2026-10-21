<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Contracts;

use SammyBoy\Reporting\Envelope\ReportEnvelope;

/**
 * The single seam between reporting and the message bus. The production
 * implementation delegates to uzapoint/eventbus-core; the fake records
 * envelopes for assertions. Nothing else should implement this.
 */
interface ReportTransport
{
    /**
     * @param array<string, mixed> $data the already-framed `data` for the bus envelope
     *
     * @throws \SammyBoy\Reporting\Exceptions\ReportPublishFailedException
     */
    public function send(ReportEnvelope $envelope, string $exchange, array $data): void;
}
