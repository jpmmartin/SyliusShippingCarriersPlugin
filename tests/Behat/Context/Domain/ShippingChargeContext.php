<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Domain;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Webmozart\Assert\Assert;

final readonly class ShippingChargeContext implements Context
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Read from the database: the order was placed through the shop, in another kernel.
     */
    #[Then('/^my order should have been charged "\$(\d+(?:\.\d{1,2})?)" for shipping$/')]
    public function myOrderShouldHaveBeenChargedForShipping(string $amount): void
    {
        $this->entityManager->clear();

        /** @var list<OrderInterface> $orders */
        $orders = $this->entityManager->getRepository(OrderInterface::class)->findBy(['state' => OrderInterface::STATE_NEW], ['id' => 'DESC'], 1);
        Assert::count($orders, 1, 'No order has been placed.');

        Assert::same($orders[0]->getShippingTotal(), (int) round((float) $amount * 100));
    }
}
