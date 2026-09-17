<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Destination;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

final readonly class DestinationTypeResolver implements DestinationTypeResolverInterface
{
    /**
     * @param RepositoryInterface<CarrierOrderDestinationInterface> $destinationRepository
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     */
    public function __construct(
        private RepositoryInterface $destinationRepository,
        private RepositoryInterface $originRepository,
    ) {
    }

    public function resolve(OrderInterface $order): ?string
    {
        if (null !== $order->getId()) {
            $destination = $this->destinationRepository->findOneBy(['order' => $order]);
            if ($destination instanceof CarrierOrderDestinationInterface && null !== $destination->getType()) {
                return $destination->getType();
            }
        }

        $channel = $order->getChannel();
        if (null === $channel) {
            return null;
        }

        $origin = $this->originRepository->findOneBy(['channel' => $channel]);

        return $origin instanceof CarrierShippingOriginInterface ? $origin->getDefaultDestinationType() : null;
    }
}
