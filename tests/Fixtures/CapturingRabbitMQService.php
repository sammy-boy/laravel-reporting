<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Fixtures;

use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Uzapoint\EventBus\RabbitMQService;

/**
 * The real eventbus-core publisher with only the socket replaced: connect() is
 * overridden to install a mocked channel, so publish() still builds the
 * {meta, data} envelope, declares the exchange and waits for confirms exactly
 * as in production. Captures what would have hit the broker.
 */
final class CapturingRabbitMQService extends RabbitMQService
{
    /** @var list<array{message: AMQPMessage, exchange: string, routing_key: string}> */
    public array $published = [];

    public int $exchangeDeclarations = 0;

    public int $confirmWaits = 0;

    protected function connect(): void
    {
        if ($this->channel !== null) {
            return;
        }

        $channel = Mockery::mock(AMQPChannel::class);

        $channel->shouldReceive('exchange_declare')
            ->andReturnUsing(function (): void {
                $this->exchangeDeclarations++;
            });

        $channel->shouldReceive('basic_publish')
            ->andReturnUsing(function (AMQPMessage $message, string $exchange, string $routingKey): void {
                $this->published[] = compact('message', 'exchange') + ['routing_key' => $routingKey];
            });

        $channel->shouldReceive('wait_for_pending_acks_returns')
            ->andReturnUsing(function (): void {
                $this->confirmWaits++;
            });

        $this->channel = $channel;
    }

    /**
     * @return array{meta: array<string, mixed>, data: array<string, mixed>}
     */
    public function lastBody(): array
    {
        $last = $this->published[array_key_last($this->published)];

        return json_decode($last['message']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
