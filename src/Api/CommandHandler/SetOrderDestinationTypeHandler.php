<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Api\CommandHandler;

use JpmMartin\SyliusShippingCarriersPlugin\Api\Command\SetOrderDestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

final readonly class SetOrderDestinationTypeHandler
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param RepositoryInterface<CarrierOrderDestinationInterface> $destinationRepository
     * @param FactoryInterface<CarrierOrderDestinationInterface> $destinationFactory
     */
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private RepositoryInterface $destinationRepository,
        private FactoryInterface $destinationFactory,
    ) {
    }

    public function __invoke(SetOrderDestinationType $setOrderDestinationType): OrderInterface
    {
        $cart = $this->orderRepository->findCartByTokenValue($setOrderDestinationType->orderTokenValue);
        Assert::isInstanceOf($cart, OrderInterface::class, 'Cart has not been found.');

        $destination = $this->destinationRepository->findOneBy(['order' => $cart]);
        if ($destination instanceof CarrierOrderDestinationInterface) {
            $destination->setType($setOrderDestinationType->type);

            return $cart;
        }

        $destination = $this->destinationFactory->createNew();
        $destination->setOrder($cart);
        $destination->setType($setOrderDestinationType->type);
        $this->destinationRepository->add($destination);

        return $cart;
    }
}
