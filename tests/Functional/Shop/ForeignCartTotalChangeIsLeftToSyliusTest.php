<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The plugin saves the recalculated cart when confirming through the API fails because the total moved, but
 * only for carts it ships. Installing it must not change what Sylius does with anybody else's order: a cart
 * with a shipping method of its own stays exactly as Sylius leaves it.
 */
final class ForeignCartTotalChangeIsLeftToSyliusTest extends WebTestCase
{
    use ShopCheckoutFixturesTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One kernel for the whole test, so every request runs on the connection that holds the
        // transaction opened below and rolled back in tearDown().
        $this->client->disableReboot();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        // Savepoints, so that saving the cart after Sylius's own transaction fails would really be saved and
        // show up below, rather than break the test's transaction (see ExpiredRateIsNotChargedUnseenTest).
        $this->entityManager->getConnection()->setNestTransactionsWithSavepoints(true);
        $this->entityManager->beginTransaction();

        $this->createStore(withCarrierMethod: false);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testACartShippedByAFlatRateIsLeftExactlyAsSyliusLeavesIt(): void
    {
        $order = $this->createCart(OrderCheckoutStates::STATE_PAYMENT_SELECTED);
        self::assertSame(self::FLAT_RATE_AMOUNT, $order->getShippingTotal());

        // The store raises its flat rate while the buyer is confirming.
        $flatRate = $this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => self::FLAT_RATE_METHOD]);
        self::assertInstanceOf(ShippingMethod::class, $flatRate);
        $flatRate->setConfiguration([self::CHANNEL => ['amount' => 999]]);
        $this->entityManager->flush();

        $this->client->request(
            'PATCH',
            sprintf('/api/v2/shop/orders/%s/complete', (string) $order->getTokenValue()),
            server: ['CONTENT_TYPE' => 'application/merge-patch+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: '{}',
        );
        self::assertResponseStatusCodeSame(409);

        $this->entityManager->clear();
        $stored = $this->entityManager->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $stored);
        self::assertSame(self::FLAT_RATE_AMOUNT, $stored->getShippingTotal(), 'The plugin does not touch an order it does not ship.');
    }
}
