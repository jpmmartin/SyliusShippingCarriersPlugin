<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Api\CommandHandler;

use JpmMartin\SyliusShippingCarriersPlugin\Api\Command\SetOrderDestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
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
        private OrderProcessorInterface $orderProcessor,
    ) {
    }

    public function __invoke(SetOrderDestinationType $setOrderDestinationType): OrderInterface
    {
        $cart = $this->orderRepository->findCartByTokenValue($setOrderDestinationType->orderTokenValue);
        Assert::isInstanceOf($cart, OrderInterface::class, 'Cart has not been found.');

        $destination = $this->destinationRepository->findOneBy(['order' => $cart]);
        if ($destination instanceof CarrierOrderDestinationInterface) {
            $destination->setType($setOrderDestinationType->type);
        } else {
            $destination = $this->destinationFactory->createNew();
            $destination->setOrder($cart);
            $destination->setType($setOrderDestinationType->type);
            $this->destinationRepository->add($destination);
        }

        // The carriers charge differently for a home than for a business, so saying which one it is has to
        // quote again. In the shop this happens on its own, because the type is saved in the same submission
        // as the address; here nothing else would ask, and the buyer would be shown the price of the other
        // kind of address until something unrelated moved the cart along.
        $this->orderProcessor->process($cart);

        return $cart;
    }
}
