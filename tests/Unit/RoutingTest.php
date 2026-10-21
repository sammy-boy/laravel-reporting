<?php

declare(strict_types=1);

namespace SammyBoy\Reporting\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SammyBoy\Reporting\Envelope\EnvelopeFormat;
use SammyBoy\Reporting\Exceptions\UnroutableReportException;
use SammyBoy\Reporting\Routing\EnvelopeFormatResolver;
use SammyBoy\Reporting\Routing\EventNameMatcher;
use SammyBoy\Reporting\Routing\ExchangeResolver;

final class RoutingTest extends TestCase
{
    #[Test]
    public function exact_match_beats_wildcard_and_wildcard_beats_default(): void
    {
        $map = ['sale.*' => 'wild', 'sale.payment.recorded' => 'exact', 'sale.payment.*' => 'longer'];

        $this->assertSame('exact', EventNameMatcher::resolve('sale.payment.recorded', $map, 'default'));
        $this->assertSame('longer', EventNameMatcher::resolve('sale.payment.refunded', $map, 'default'));
        $this->assertSame('wild', EventNameMatcher::resolve('sale.item.synced', $map, 'default'));
        $this->assertSame('default', EventNameMatcher::resolve('stock.transaction.recorded', $map, 'default'));
        $this->assertNull(EventNameMatcher::resolve('stock.transaction.recorded', $map));
    }

    #[Test]
    public function wildcard_requires_a_segment_boundary(): void
    {
        $this->assertNull(EventNameMatcher::resolve('salesforce.synced', ['sale.*' => 'x']));
    }

    #[Test]
    public function exchange_resolver_uses_default_then_overrides(): void
    {
        $resolver = new ExchangeResolver('sales.events', ['invoice.*' => 'billing.events']);

        $this->assertSame('sales.events', $resolver->resolve('sale.item.synced'));
        $this->assertSame('billing.events', $resolver->resolve('invoice.snapshot.synced'));
    }

    #[Test]
    public function exchange_resolver_refuses_to_guess(): void
    {
        $this->expectException(UnroutableReportException::class);

        (new ExchangeResolver(null, ['invoice.*' => 'billing.events']))->resolve('sale.item.synced');
    }

    #[Test]
    public function format_resolver_defaults_and_overrides(): void
    {
        $resolver = new EnvelopeFormatResolver(EnvelopeFormat::Flat, ['accounts.expense.*' => 'wrapped']);

        $this->assertSame(EnvelopeFormat::Flat, $resolver->resolve('sale.item.synced'));
        $this->assertSame(EnvelopeFormat::Wrapped, $resolver->resolve('accounts.expense.created'));
    }
}
