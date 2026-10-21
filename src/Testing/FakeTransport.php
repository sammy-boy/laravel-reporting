<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use SammyBoy\Reporting\Contracts\ReportTransport;
use SammyBoy\Reporting\Envelope\ReportEnvelope;
use SammyBoy\Reporting\Exceptions\ReportPublishFailedException;
use Throwable;

/**
 * Records every publication instead of touching RabbitMQ. Installed with
 * Reporting::fake(); assert against it in producer tests.
 */
final class FakeTransport implements ReportTransport
{
    /** @var list<PublishedReport> */
    private array $published = [];

    private ?Throwable $failure = null;

    public function send(ReportEnvelope $envelope, string $exchange, array $data): void
    {
        if ($this->failure !== null) {
            throw ReportPublishFailedException::wrap($envelope, $exchange, $this->failure);
        }

        $this->published[] = new PublishedReport($envelope, $exchange, $data);
    }

    /**
     * Make every subsequent send() fail as the broker would, so producers can
     * test their handling of ReportPublishFailedException.
     */
    public function failWith(Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function recover(): void
    {
        $this->failure = null;
    }

    /**
     * @return list<PublishedReport>
     */
    public function published(?string $eventName = null): array
    {
        if ($eventName === null) {
            return $this->published;
        }

        return array_values(array_filter(
            $this->published,
            fn (PublishedReport $r) => $r->envelope->eventName === $eventName,
        ));
    }

    public function assertPublished(string $eventName, ?Closure $callback = null): void
    {
        $matches = $this->published($eventName);

        Assert::assertNotEmpty($matches, sprintf('Expected report "%s" to be published, but it was not.', $eventName));

        if ($callback === null) {
            return;
        }

        foreach ($matches as $match) {
            if ($callback($match->envelope, $match->exchange, $match->data) === true) {
                return;
            }
        }

        Assert::fail(sprintf('Report "%s" was published but none matched the given expectation.', $eventName));
    }

    public function assertPublishedTimes(string $eventName, int $times): void
    {
        Assert::assertCount(
            $times,
            $this->published($eventName),
            sprintf('Expected report "%s" to be published %d time(s).', $eventName, $times),
        );
    }

    public function assertNotPublished(string $eventName): void
    {
        Assert::assertEmpty(
            $this->published($eventName),
            sprintf('Did not expect report "%s" to be published.', $eventName),
        );
    }

    public function assertNothingPublished(): void
    {
        Assert::assertEmpty($this->published, 'Expected no reports to be published.');
    }
}
