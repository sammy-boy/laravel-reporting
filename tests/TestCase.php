<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;
use SammyBoy\Reporting\ReportingServiceProvider;
use Uzapoint\EventBus\EventBusServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            EventBusServiceProvider::class,
            ReportingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);

        $config->set('app.name', 'sales-service');
        $config->set('reporting.exchange', 'sales.events');
        $config->set('reporting.exchanges', ['invoice.*' => 'billing.events']);
        $config->set('database.default', 'testing');
    }
}
