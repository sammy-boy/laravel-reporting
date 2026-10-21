<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SammyBoy\Reporting\Contracts\ReportTransport;
use SammyBoy\Reporting\Envelope\EnvelopeFactory;
use SammyBoy\Reporting\Facades\Reporting as ReportingFacade;
use SammyBoy\Reporting\Reporting;
use SammyBoy\Reporting\Routing\EnvelopeFormatResolver;
use SammyBoy\Reporting\Routing\ExchangeResolver;
use SammyBoy\Reporting\Testing\FakeTransport;
use SammyBoy\Reporting\Tests\TestCase;
use SammyBoy\Reporting\Tracing\CorrelationId;
use SammyBoy\Reporting\Transport\EventBusTransport;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function merges_default_configuration(): void
    {
        $this->assertSame('_reporting', config('reporting.envelope.metadata_key'));
        $this->assertSame('flat', config('reporting.envelope.format'));
        $this->assertTrue(config('reporting.publish.after_commit'));
        $this->assertTrue(config('reporting.tracing.generate_if_missing'));
    }

    #[Test]
    public function binds_every_collaborator_and_the_eventbus_transport_by_default(): void
    {
        $this->assertInstanceOf(Reporting::class, $this->app->make(Reporting::class));
        $this->assertInstanceOf(EventBusTransport::class, $this->app->make(ReportTransport::class));
        $this->assertInstanceOf(EnvelopeFactory::class, $this->app->make(EnvelopeFactory::class));
        $this->assertInstanceOf(ExchangeResolver::class, $this->app->make(ExchangeResolver::class));
        $this->assertInstanceOf(EnvelopeFormatResolver::class, $this->app->make(EnvelopeFormatResolver::class));
        $this->assertInstanceOf(CorrelationId::class, $this->app->make(CorrelationId::class));
    }

    #[Test]
    public function reporting_is_a_singleton_and_correlation_is_scoped(): void
    {
        $this->assertSame($this->app->make(Reporting::class), $this->app->make(Reporting::class));

        $correlation = $this->app->make(CorrelationId::class);
        $correlation->set('pinned');
        $this->assertSame('pinned', $this->app->make(CorrelationId::class)->current());

        $this->app->forgetScopedInstances();

        $this->assertNotSame('pinned', $this->app->make(CorrelationId::class)->current());
    }

    #[Test]
    public function correlation_header_name_comes_from_eventbus_core_config(): void
    {
        $this->assertSame('X-Correlation-ID', $this->app->make(CorrelationId::class)->header());

        config()->set('eventbus.tracing.header', 'X-Trace');
        $this->app->forgetScopedInstances();

        $this->assertSame('X-Trace', $this->app->make(CorrelationId::class)->header());
    }

    #[Test]
    public function facade_fake_swaps_the_transport_for_the_singleton(): void
    {
        $fake = ReportingFacade::fake();

        $this->assertInstanceOf(FakeTransport::class, $fake);
        $this->assertSame($fake, $this->app->make(ReportTransport::class));

        ReportingFacade::publish(new \SammyBoy\Reporting\Tests\Fixtures\OrderSnapshot());

        $fake->assertPublished('order.snapshot.synced');
    }

    #[Test]
    public function config_is_publishable(): void
    {
        $target = $this->app->configPath('reporting.php');
        @unlink($target);

        $this->artisan('vendor:publish', ['--tag' => 'reporting-config'])->assertSuccessful();

        $this->assertFileExists($target);
        @unlink($target);
    }
}
