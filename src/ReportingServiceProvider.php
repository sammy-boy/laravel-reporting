<?php

declare(strict_types=1);

namespace SammyBoy\Reporting;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use SammyBoy\Reporting\Contracts\ReportTransport;
use SammyBoy\Reporting\Envelope\EnvelopeFactory;
use SammyBoy\Reporting\Envelope\EnvelopeFormat;
use SammyBoy\Reporting\Routing\EnvelopeFormatResolver;
use SammyBoy\Reporting\Routing\ExchangeResolver;
use SammyBoy\Reporting\Tracing\CorrelationId;
use SammyBoy\Reporting\Transport\EventBusTransport;

final class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/reporting.php', 'reporting');

        $this->app->scoped(CorrelationId::class, function (Container $app): CorrelationId {
            $config = $app->make(Repository::class);

            return new CorrelationId(
                $app,
                (string) $config->get('eventbus.tracing.header', 'X-Correlation-ID'),
                (bool) $config->get('reporting.tracing.generate_if_missing', true),
            );
        });

        $this->app->singleton(ExchangeResolver::class, function (Container $app): ExchangeResolver {
            $config = $app->make(Repository::class);

            return new ExchangeResolver(
                $config->get('reporting.exchange'),
                (array) $config->get('reporting.exchanges', []),
            );
        });

        $this->app->singleton(EnvelopeFormatResolver::class, function (Container $app): EnvelopeFormatResolver {
            $config = $app->make(Repository::class);

            return new EnvelopeFormatResolver(
                EnvelopeFormat::from((string) $config->get('reporting.envelope.format', 'flat')),
                (array) $config->get('reporting.envelope.formats', []),
            );
        });

        $this->app->singleton(EnvelopeFactory::class, function (Container $app): EnvelopeFactory {
            return new EnvelopeFactory(
                $app->make(CorrelationId::class),
                (string) $app->make(Repository::class)->get('app.name', 'laravel'),
            );
        });

        $this->app->singleton(ReportTransport::class, EventBusTransport::class);

        $this->app->singleton(Reporting::class, function (Container $app): Reporting {
            $config = $app->make(Repository::class);

            return new Reporting(
                $app->make(EnvelopeFactory::class),
                $app->make(ExchangeResolver::class),
                $app->make(EnvelopeFormatResolver::class),
                $app->make(ReportTransport::class),
                $app->make(Dispatcher::class),
                $app->make(ConnectionResolverInterface::class),
                (string) $config->get('reporting.envelope.metadata_key', '_reporting'),
                (bool) $config->get('reporting.publish.after_commit', true),
                $config->get('reporting.publish.connection'),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/reporting.php' => $this->app->configPath('reporting.php'),
            ], 'reporting-config');
        }
    }
}
