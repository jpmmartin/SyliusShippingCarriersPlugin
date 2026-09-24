<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierCallScope;
use PHPUnit\Framework\TestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;

final class CarrierCallScopeTest extends TestCase
{
    public function testOutsideACallThereIsNoTimeoutOfAChannel(): void
    {
        self::assertNull((new CarrierCallScope())->carrierTimeout());
    }

    public function testACallIsMadeWithTheSettingsItIsGiven(): void
    {
        $scope = new CarrierCallScope();

        $seen = $scope->within(CarrierSettingsFactory::provider(carrierTimeout: 2.5)->defaults(), static fn (): ?float => $scope->carrierTimeout());

        self::assertSame(2.5, $seen);
        self::assertNull($scope->carrierTimeout());
    }

    /**
     * A call made inside another gets its own settings, and the one it was in gets its back.
     */
    public function testACallInsideAnotherLeavesTheOuterOneItsSettings(): void
    {
        $scope = new CarrierCallScope();
        $seen = [];

        $scope->within(CarrierSettingsFactory::provider(carrierTimeout: 2.5)->defaults(), static function () use ($scope, &$seen): void {
            $scope->within(CarrierSettingsFactory::provider(carrierTimeout: 7.0)->defaults(), static function () use ($scope, &$seen): void {
                $seen[] = $scope->carrierTimeout();
            });
            $seen[] = $scope->carrierTimeout();
        });

        self::assertSame([7.0, 2.5], $seen);
    }

    /**
     * A carrier that fails does not leave its channel's settings behind for the next call.
     */
    public function testACallThatFailsLeavesNothingBehind(): void
    {
        $scope = new CarrierCallScope();

        try {
            $scope->within(CarrierSettingsFactory::provider(carrierTimeout: 2.5)->defaults(), static fn (): never => throw new \RuntimeException('The carrier did not answer.'));
        } catch (\RuntimeException) {
        }

        self::assertNull($scope->carrierTimeout());
    }
}
