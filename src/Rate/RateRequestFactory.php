<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\AddressFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns a shipment into what a carrier is asked to rate: from the origin of its channel to the shipping address
 * of its order, in the packages the packaging strategy builds.
 */
final class RateRequestFactory implements ResetInterface
{
    /**
     * The channels already logged as having no origin in this request. The shipping step asks for every shipping
     * method of the plugin, so the fact would otherwise be logged once per method.
     *
     * @var array<string, true>
     */
    private array $channelsLoggedWithoutOrigin = [];

    /** @var array<string, true> The channels whose incomplete origin has already been logged in this request. */
    private array $channelsLoggedWithAnIncompleteOrigin = [];

    /**
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     */
    public function __construct(
        private readonly RepositoryInterface $originRepository,
        private readonly PackagingStrategyInterface $packagingStrategy,
        private readonly DestinationTypeResolverInterface $destinationTypeResolver,
        private readonly AddressFactory $addressFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Null when the shipment cannot be rated: its order has no complete shipping address, its channel has no
     * complete origin, which is logged, or its units cannot be packed, which the packaging strategy logs.
     */
    public function create(ShipmentInterface $shipment): ?RateRequest
    {
        $order = $shipment->getOrder();
        if (!$order instanceof OrderInterface) {
            return null;
        }

        // Checked first: without an address there is nothing to rate, so nothing is packed either.
        $destination = $this->addressFactory->forDestination(
            $order->getShippingAddress(),
            DestinationType::RESIDENTIAL === $this->destinationTypeResolver->resolve($order),
        );
        if (null === $destination) {
            return null;
        }

        $channel = $order->getChannel();
        if (null === $channel) {
            return null;
        }

        $origin = $this->originRepository->findOneBy(['channel' => $channel]);
        if (!$origin instanceof CarrierShippingOriginInterface) {
            $this->logChannelWithoutOrigin((string) $channel->getCode());

            return null;
        }

        $originAddress = $this->addressFactory->forOrigin($origin);
        if (null === $originAddress) {
            $this->logIncompleteOrigin((string) $channel->getCode(), AddressFactory::missingFromOrigin($origin));

            return null;
        }

        try {
            $packages = $this->packagingStrategy->pack($shipment, $origin);
        } catch (UnpackableShipmentException) {
            return null;
        }

        return new RateRequest($originAddress, $destination, $packages);
    }

    public function reset(): void
    {
        $this->channelsLoggedWithoutOrigin = [];
        $this->channelsLoggedWithAnIncompleteOrigin = [];
    }

    /**
     * The same silence as having no origin at all, so it gets the same shout: from the outside the method
     * simply is not there, and nobody could tell the two apart.
     *
     * @param list<string> $missing
     */
    private function logIncompleteOrigin(string $channelCode, array $missing): void
    {
        if (isset($this->channelsLoggedWithAnIncompleteOrigin[$channelCode])) {
            return;
        }

        $this->channelsLoggedWithAnIncompleteOrigin[$channelCode] = true;
        $this->logger->error('The shipping origin of the channel {channel} has no {missing}, so no carrier shipping method is offered in it.', [
            'channel' => $channelCode,
            'missing' => implode(', ', $missing),
        ]);
    }

    private function logChannelWithoutOrigin(string $channelCode): void
    {
        if (isset($this->channelsLoggedWithoutOrigin[$channelCode])) {
            return;
        }

        $this->channelsLoggedWithoutOrigin[$channelCode] = true;
        $this->logger->error('The channel {channel} has no shipping origin, so no carrier shipping method is offered in it.', [
            'channel' => $channelCode,
        ]);
    }
}
