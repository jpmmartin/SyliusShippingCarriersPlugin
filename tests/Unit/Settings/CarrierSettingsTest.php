<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Settings;

use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;

/**
 * The same limits the configuration puts on a written value, for a value that only exists once the store runs.
 */
final class CarrierSettingsTest extends TestCase
{
    public function testTheDefaultsCanBeUsedForEverything(): void
    {
        $settings = CarrierSettingsFactory::provider()->defaults();

        $settings->assertRatesUsable();
        $settings->assertTrackingUsable();
        $settings->assertPurgeUsable();
        $settings->assertLabelsUsable('ups');
        $settings->assertLabelsUsable('fedex');

        $this->addToAssertionCount(5);
    }

    /**
     * The minimum itself is allowed, as it is in the configuration.
     */
    public function testTheMinimumsAreAllowed(): void
    {
        $settings = CarrierSettingsFactory::provider(
            carrierTimeout: CarrierSettings::MIN_CARRIER_TIMEOUT,
            rateLifetime: 1,
            rateRetention: 1,
            trackingLifetime: 1,
            documentsRetention: 1,
            temporaryDocumentsRetention: 1,
        )->defaults();

        $settings->assertRatesUsable();
        $settings->assertTrackingUsable();
        $settings->assertPurgeUsable();

        $this->addToAssertionCount(3);
    }

    /**
     * @param \Closure(CarrierSettings): void $use
     */
    #[DataProvider('unusable')]
    public function testAValueItWouldHaveBeenRefusedWrittenIsRefusedNamingTheSettingAndTheValue(CarrierSettings $settings, \Closure $use, string $message): void
    {
        $this->expectException(InvalidCarrierSettingException::class);
        $this->expectExceptionMessage($message);

        $use($settings);
    }

    /**
     * @return iterable<string, array{CarrierSettings, \Closure(CarrierSettings): void, string}>
     */
    public static function unusable(): iterable
    {
        $rates = static fn (CarrierSettings $settings) => $settings->assertRatesUsable();
        $tracking = static fn (CarrierSettings $settings) => $settings->assertTrackingUsable();
        $purge = static fn (CarrierSettings $settings) => $settings->assertPurgeUsable();
        $upsLabels = static fn (CarrierSettings $settings) => $settings->assertLabelsUsable('ups');
        $fedexLabels = static fn (CarrierSettings $settings) => $settings->assertLabelsUsable('fedex');

        yield 'timeout below the minimum' => [CarrierSettingsFactory::provider(carrierTimeout: 0.09)->defaults(), $rates, 'carrier_timeout is 0.09'];
        yield 'rate lifetime of zero' => [CarrierSettingsFactory::provider(rateLifetime: 0)->defaults(), $rates, 'rate_lifetime is 0'];
        yield 'negative rate retention' => [CarrierSettingsFactory::provider(rateRetention: -5)->defaults(), $rates, 'rate_retention is -5'];
        yield 'retention below the lifetime' => [CarrierSettingsFactory::provider(rateLifetime: 900, rateRetention: 899)->defaults(), $rates, 'rate_retention is 899 and rate_lifetime is 900'];
        yield 'tracking lifetime of zero' => [CarrierSettingsFactory::provider(trackingLifetime: 0)->defaults(), $tracking, 'tracking_lifetime is 0'];
        yield 'tracking with a timeout of zero' => [CarrierSettingsFactory::provider(carrierTimeout: 0.0)->defaults(), $tracking, 'carrier_timeout is 0'];
        yield 'documents retention of zero' => [CarrierSettingsFactory::provider(documentsRetention: 0)->defaults(), $purge, 'documents_retention is 0'];
        yield 'temporary documents retention of zero' => [CarrierSettingsFactory::provider(temporaryDocumentsRetention: 0)->defaults(), $purge, 'temporary_documents_retention is 0'];
        yield 'a format UPS does not print' => [CarrierSettingsFactory::provider(labelFormats: ['ups' => 'PDF'])->defaults(), $upsLabels, 'label_formats.ups is "PDF"'];
        yield 'an empty format' => [CarrierSettingsFactory::provider(labelFormats: ['fedex' => ''])->defaults(), $fedexLabels, 'label_formats.fedex is ""'];
        yield 'labels with a timeout of zero' => [CarrierSettingsFactory::provider(carrierTimeout: 0.0)->defaults(), $upsLabels, 'carrier_timeout is 0'];
    }

    /**
     * Only what an operation uses stops it: a purge does not care how long a carrier is waited for.
     */
    public function testAValueAnOperationDoesNotUseDoesNotStopIt(): void
    {
        $settings = CarrierSettingsFactory::provider(carrierTimeout: 0.0, trackingLifetime: 0)->defaults();

        $settings->assertPurgeUsable();

        $this->addToAssertionCount(1);
    }

    public function testACarrierWithoutAFormatOfItsOwnGetsItsDefault(): void
    {
        $settings = CarrierSettingsFactory::provider(labelFormats: ['fedex' => 'ZPLII'])->defaults();

        self::assertSame('GIF', $settings->labelFormat('ups'));
        self::assertSame('ZPLII', $settings->labelFormat('fedex'));
    }
}
