<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Facades;

use Illuminate\Support\Facades\Facade;
use SammyBoy\Reporting\Contracts\Reportable;
use SammyBoy\Reporting\Contracts\ReportTransport;
use SammyBoy\Reporting\Envelope\ReportEnvelope;
use SammyBoy\Reporting\Reporting as ReportingService;
use SammyBoy\Reporting\Testing\FakeTransport;

/**
 * @method static ReportEnvelope publish(Reportable $report, ?string $correlationId = null)
 * @method static ReportEnvelope snapshot(Reportable $report, ?string $correlationId = null)
 * @method static ReportEnvelope event(Reportable $report, ?string $correlationId = null)
 *
 * @see ReportingService
 */
final class Reporting extends Facade
{
    /**
     * Swap the real transport for a recording fake. The Reporting singleton is
     * rebuilt so the fake is what it publishes through.
     */
    public static function fake(): FakeTransport
    {
        $fake = new FakeTransport();

        static::$app->instance(ReportTransport::class, $fake);
        static::$app->forgetInstance(ReportingService::class);
        static::clearResolvedInstance(ReportingService::class);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ReportingService::class;
    }
}
