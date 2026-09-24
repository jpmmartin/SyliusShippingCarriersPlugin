<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Settings;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;

final class CarrierSettingsProviderTest extends TestCase
{
    /** @var array<string, object> The origin of each channel, by channel code */
    private array $origins = [];

    private int $lookups = 0;

    public function testWithoutAChannelTheSettingsAreTheConfigurations(): void
    {
        $provider = $this->provider();

        self::assertSame($provider->defaults(), $provider->forChannel(null));
    }

    public function testAChannelWithoutAnOriginHasTheConfigurationsSettings(): void
    {
        $provider = $this->provider();

        self::assertSame($provider->defaults(), $provider->forChannel($this->channel('WEB')));
    }

    public function testAnOriginThatSaysNothingLeavesTheConfigurationsSettings(): void
    {
        $this->origins['WEB'] = new CarrierShippingOrigin();

        $settings = $this->provider()->forChannel($this->channel('WEB'));

        self::assertEquals($this->provider()->defaults(), $settings);
    }

    /**
     * Each value the origin gives replaces the configuration's, and nothing else does.
     */
    public function testWhatTheOriginSaysReplacesTheConfigurationsAndOnlyThat(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setRateLifetime(1800);
        $origin->setDocumentsRetention(30 * 24 * 60 * 60);
        $origin->setLabelFormat('ups', 'ZPL');
        $this->origins['WEB'] = $origin;

        $settings = $this->provider()->forChannel($this->channel('WEB'));

        self::assertSame(1800, $settings->rateLifetime);
        self::assertSame(30 * 24 * 60 * 60, $settings->documentsRetention);
        self::assertSame('ZPL', $settings->labelFormat('ups'));

        self::assertSame(10.0, $settings->carrierTimeout);
        self::assertSame(86400, $settings->rateRetention);
        self::assertSame(300, $settings->trackingLifetime);
        self::assertSame('PDF', $settings->labelFormat('fedex'));
    }

    /**
     * A document no row names belongs to no channel, so how long it is left alone is the store's.
     */
    public function testTheRetentionOfDocumentsNoRowNamesIsAlwaysTheConfigurations(): void
    {
        $this->origins['WEB'] = new CarrierShippingOrigin();

        self::assertSame(3600, $this->provider(temporaryDocumentsRetention: 3600)->forChannel($this->channel('WEB'))->temporaryDocumentsRetention);
    }

    /**
     * A store whose own origin model knows nothing of these settings keeps working, on the configuration.
     */
    public function testAnOriginModelWithoutChannelSettingsLeavesTheConfigurations(): void
    {
        $this->origins['WEB'] = $this->createStub(CarrierShippingOriginInterface::class);
        $provider = $this->provider();

        self::assertSame($provider->defaults(), $provider->forChannel($this->channel('WEB')));
    }

    public function testTwoChannelsHaveEachTheirOwn(): void
    {
        $web = new CarrierShippingOrigin();
        $web->setTrackingLifetime(60);
        $this->origins['WEB'] = $web;
        $mobile = new CarrierShippingOrigin();
        $mobile->setTrackingLifetime(600);
        $this->origins['MOBILE'] = $mobile;
        $provider = $this->provider();

        self::assertSame(60, $provider->forChannel($this->channel('WEB'))->trackingLifetime);
        self::assertSame(600, $provider->forChannel($this->channel('MOBILE'))->trackingLifetime);
    }

    /**
     * Rates ask once for every service of a shipment; the origin is read once. The next request reads it again,
     * because an administrator may have changed it in between.
     */
    public function testAChannelIsReadOncePerRequest(): void
    {
        $this->origins['WEB'] = new CarrierShippingOrigin();
        $provider = $this->provider();

        $provider->forChannel($this->channel('WEB'));
        $provider->forChannel($this->channel('WEB'));
        self::assertSame(1, $this->lookups);

        $provider->reset();
        $provider->forChannel($this->channel('WEB'));
        self::assertSame(2, $this->lookups);
    }

    /**
     * The provider hands over what applies; whether it can be used is checked by whoever uses it.
     */
    public function testWhatTheChannelGivesIsCheckedByWhoeverUsesIt(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setRateRetention(600);
        $this->origins['WEB'] = $origin;

        $settings = $this->provider()->forChannel($this->channel('WEB'));

        $this->expectException(InvalidCarrierSettingException::class);
        $this->expectExceptionMessage('rate_retention is 600 and rate_lifetime is 900');

        $settings->assertRatesUsable();
    }

    private function provider(int $temporaryDocumentsRetention = 24 * 60 * 60): CarrierSettingsProvider
    {
        /** @var RepositoryInterface<CarrierShippingOriginInterface>&\PHPUnit\Framework\MockObject\Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturnCallback(function (array $criteria): ?object {
            ++$this->lookups;
            $channel = $criteria['channel'] ?? null;

            return $channel instanceof Channel ? ($this->origins[(string) $channel->getCode()] ?? null) : null;
        });

        return CarrierSettingsFactory::provider(
            temporaryDocumentsRetention: $temporaryDocumentsRetention,
            labelFormats: ['ups' => 'GIF', 'fedex' => 'PDF'],
            originRepository: $repository,
        );
    }

    private function channel(string $code): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);

        return $channel;
    }
}
