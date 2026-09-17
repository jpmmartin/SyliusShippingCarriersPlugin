<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Destination;

use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolver;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestination;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * The buyer's choice, or the origin's default without one.
 */
final class DestinationTypeResolverTest extends TestCase
{
    public function testTheBuyersChoiceWins(): void
    {
        $destination = new CarrierOrderDestination();
        $destination->setType('residential');

        self::assertSame('residential', $this->resolver($destination, $this->origin('commercial'))->resolve($this->order(7)));
    }

    public function testWithoutAChoiceTheOriginsDefaultApplies(): void
    {
        self::assertSame('commercial', $this->resolver(null, $this->origin('commercial'))->resolve($this->order(7)));
    }

    public function testAnOrderNotSavedYetHasNoChoiceToLookFor(): void
    {
        $destination = new CarrierOrderDestination();
        $destination->setType('residential');

        self::assertSame('commercial', $this->resolver($destination, $this->origin('commercial'))->resolve($this->order(null)));
    }

    public function testWithoutAChoiceNorAnOriginThereIsNoType(): void
    {
        self::assertNull($this->resolver(null, null)->resolve($this->order(7)));
    }

    private function resolver(?CarrierOrderDestinationInterface $destination, ?CarrierShippingOriginInterface $origin): DestinationTypeResolver
    {
        /** @var RepositoryInterface<CarrierOrderDestinationInterface>&Stub $destinationRepository */
        $destinationRepository = $this->createStub(RepositoryInterface::class);
        $destinationRepository->method('findOneBy')->willReturn($destination);

        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $originRepository */
        $originRepository = $this->createStub(RepositoryInterface::class);
        $originRepository->method('findOneBy')->willReturn($origin);

        return new DestinationTypeResolver($destinationRepository, $originRepository);
    }

    private function origin(string $defaultDestinationType): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setDefaultDestinationType($defaultDestinationType);

        return $origin;
    }

    private function order(?int $id): OrderInterface
    {
        $order = $this->createStub(OrderInterface::class);
        $order->method('getId')->willReturn($id);
        $order->method('getChannel')->willReturn(new Channel());

        return $order;
    }
}
