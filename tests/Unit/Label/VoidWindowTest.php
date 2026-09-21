<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\VoidWindow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Ninety days against twelve hours. It is the difference that makes this worth computing at all: an operator
 * used to UPS working a FedEx shipment finds out too late unless somebody does the arithmetic for them.
 */
final class VoidWindowTest extends TestCase
{
    private const ISSUED_AT = '2026-09-01 10:00:00';

    public function testUpsCancelsByItselfForNinetyDays(): void
    {
        $window = $this->windowAt('2026-09-02 10:00:00', CarrierCredentialsInterface::CARRIER_UPS);

        self::assertNotNull($window);
        self::assertTrue($window->isOpen());
        self::assertEquals(new \DateTimeImmutable('2026-11-30 10:00:00'), $window->selfServiceUntil);
        self::assertSame(89 * 86400, $window->secondsLeft);
    }

    /**
     * Twelve hours, not ninety days. This is the whole point of showing the window.
     */
    public function testFedexGivesTwelveHours(): void
    {
        $window = $this->windowAt('2026-09-01 14:00:00', CarrierCredentialsInterface::CARRIER_FEDEX);

        self::assertNotNull($window);
        self::assertTrue($window->isOpen());
        self::assertEquals(new \DateTimeImmutable('2026-09-01 22:00:00'), $window->selfServiceUntil);
        self::assertSame(8 * 3600, $window->secondsLeft);
        self::assertNull($window->assistedUntil, 'FedEx has no period where it cancels by hand.');
    }

    public function testAFedexShipmentOfYesterdayCanNoLongerBeCancelled(): void
    {
        $window = $this->windowAt('2026-09-02 10:00:00', CarrierCredentialsInterface::CARRIER_FEDEX);

        self::assertNotNull($window);
        self::assertFalse($window->isOpen());
        self::assertSame(0, $window->secondsLeft);
        self::assertFalse($window->needsTheCarrierItself(new \DateTimeImmutable('2026-09-02 10:00:00')));
    }

    /**
     * Between ninety and a hundred and eighty days UPS still cancels it, but somebody has to ring them.
     */
    public function testUpsStillCancelsByHandUpToAHundredAndEightyDays(): void
    {
        $now = new \DateTimeImmutable('2026-12-01 10:00:00');
        $window = $this->windowAt('2026-12-01 10:00:00', CarrierCredentialsInterface::CARRIER_UPS);

        self::assertNotNull($window);
        self::assertFalse($window->isOpen());
        self::assertTrue($window->needsTheCarrierItself($now));
        self::assertEquals(new \DateTimeImmutable('2027-02-28 10:00:00'), $window->assistedUntil);
    }

    public function testAfterAHundredAndEightyDaysNobodyCancelsAUpsShipment(): void
    {
        $now = new \DateTimeImmutable('2027-03-01 10:00:00');
        $window = $this->windowAt('2027-03-01 10:00:00', CarrierCredentialsInterface::CARRIER_UPS);

        self::assertNotNull($window);
        self::assertFalse($window->isOpen());
        self::assertFalse($window->needsTheCarrierItself($now));
    }

    /**
     * A carrier added later must not have a window guessed for it.
     */
    public function testACarrierWhoseWindowIsNotKnownSaysNothing(): void
    {
        self::assertNull($this->windowAt('2026-09-02 10:00:00', 'dhl'));
    }

    private function windowAt(string $now, string $carrier): ?\JpmMartin\SyliusShippingCarriersPlugin\Label\VoidWindowState
    {
        return (new VoidWindow(new MockClock($now)))->of($carrier, new \DateTimeImmutable(self::ISSUED_AT));
    }
}
