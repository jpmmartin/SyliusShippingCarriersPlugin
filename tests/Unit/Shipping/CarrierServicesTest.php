<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

final class CarrierServicesTest extends TestCase
{
    /**
     * A code such as "12" is an integer key in a PHP array; it must still compare with the code a shipping method
     * stores.
     */
    public function testCodesAreStringsEvenWhenTheyLookLikeNumbers(): void
    {
        $services = new CarrierServices(['ups' => ['03' => 'UPS Ground', '12' => 'A numeric code']]);

        self::assertSame(['03', '12'], $services->codes('ups'));
        self::assertSame('A numeric code', $services->name('ups', '12'));
    }

    public function testAServiceOutsideTheListOfItsCarrierHasNoName(): void
    {
        $services = new CarrierServices(['ups' => ['03' => 'UPS Ground'], 'fedex' => ['FEDEX_GROUND' => 'FedEx Ground']]);

        self::assertNull($services->name('ups', 'FEDEX_GROUND'));
        self::assertNull($services->name('ups', '3'));
        self::assertSame([], $services->codes('dhl'));
    }

    /**
     * A shipping method may be offered in several channels, so it is chosen from every list: the configuration's
     * and each channel's.
     */
    public function testAMethodIsChosenFromTheConfigurationsServicesAndEveryChannels(): void
    {
        $services = $this->servicesWith(['WEB' => ['02' => 'UPS 2nd Day Air'], 'MOBILE' => ['07' => 'UPS Worldwide Express']]);

        self::assertSame(['03', '07', '02'], $services->codes('ups'));
    }

    /**
     * The configuration's name, or else the first channel's by channel code, whatever order the origins come in.
     */
    public function testAServiceIsNamedAsTheConfigurationNamesItOrElseAsTheFirstChannelDoes(): void
    {
        $services = $this->servicesWith(['WEB' => ['02' => 'Two days', '03' => 'Ground, from the web'], 'MOBILE' => ['02' => '2nd Day']]);

        self::assertSame('UPS Ground', $services->name('ups', '03'));
        self::assertSame('2nd Day', $services->name('ups', '02'));
        self::assertNull($services->name('ups', '07'));
    }

    /**
     * The list of a channel is the configuration's and its own, and its own name for a service is the one it uses.
     */
    public function testAChannelsListIsTheConfigurationsAndItsOwn(): void
    {
        $services = $this->servicesWith(['WEB' => ['02' => 'UPS 2nd Day Air', '03' => 'Ground, from the web']]);

        self::assertSame('UPS 2nd Day Air', $services->nameInChannel('ups', '02', $this->channel('WEB')));
        self::assertSame('Ground, from the web', $services->nameInChannel('ups', '03', $this->channel('WEB')));
        self::assertSame('UPS Ground', $services->nameInChannel('ups', '03', $this->channel('MOBILE')));
        self::assertNull($services->nameInChannel('ups', '02', $this->channel('MOBILE')));
    }

    /**
     * Read once per request, and again on the next: an administrator may have changed a channel's in between.
     */
    public function testTheChannelsServicesAreReadAgainForTheNextRequest(): void
    {
        $origin = $this->origin('WEB', ['02' => 'UPS 2nd Day Air']);
        $services = new CarrierServices(['ups' => ['03' => 'UPS Ground']], $this->repository([$origin]));
        self::assertSame(['03', '02'], $services->codes('ups'));

        $origin->setServices('ups', []);
        self::assertSame(['03', '02'], $services->codes('ups'));

        $services->reset();
        self::assertSame(['03'], $services->codes('ups'));
    }

    /**
     * @param array<string, array<string, string>> $byChannel UPS services each channel adds, by channel code
     */
    private function servicesWith(array $byChannel): CarrierServices
    {
        $origins = [];
        foreach ($byChannel as $code => $services) {
            $origins[] = $this->origin($code, $services);
        }

        return new CarrierServices(['ups' => ['03' => 'UPS Ground']], $this->repository($origins));
    }

    /**
     * @param array<string, string> $services
     */
    private function origin(string $channelCode, array $services): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setChannel($this->channel($channelCode));
        $origin->setServices('ups', $services);

        return $origin;
    }

    /**
     * @param list<CarrierShippingOrigin> $origins
     *
     * @return RepositoryInterface<CarrierShippingOriginInterface>
     */
    private function repository(array $origins): RepositoryInterface
    {
        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findAll')->willReturn($origins);

        return $repository;
    }

    private function channel(string $code): Channel
    {
        $channel = new Channel();
        $channel->setCode($code);

        return $channel;
    }
}
