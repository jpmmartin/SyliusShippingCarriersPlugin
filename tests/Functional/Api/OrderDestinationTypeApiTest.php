<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Api;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestination;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Shop\ShopCheckoutFixturesTrait;

/**
 * A buyer shopping through the API says whether their cart goes to a home or a business.
 */
final class OrderDestinationTypeApiTest extends WebTestCase
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
        $this->entityManager->beginTransaction();

        $this->createStore();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testTheBuyerSaysWhereTheirCartIsGoing(): void
    {
        $cart = $this->createCart(OrderCheckoutStates::STATE_CART);

        $this->setDestinationType($cart, 'residential');
        self::assertResponseIsSuccessful();
        self::assertSame('residential', $this->storedTypeOf($cart));

        // And changes their mind.
        $this->setDestinationType($cart, 'commercial');
        self::assertResponseIsSuccessful();
        self::assertSame('commercial', $this->storedTypeOf($cart));
    }

    public function testAnythingElseIsRefused(): void
    {
        $cart = $this->createCart(OrderCheckoutStates::STATE_CART);

        $this->setDestinationType($cart, 'office');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->storedTypeOf($cart));
    }

    /**
     * Only a cart is still being shopped: an order already placed is not found.
     */
    public function testAnOrderThatIsNoLongerACartIsNotFound(): void
    {
        $order = $this->createCart(OrderCheckoutStates::STATE_COMPLETED);
        $order->setState(OrderInterface::STATE_NEW);
        $this->entityManager->flush();

        $this->setDestinationType($order, 'residential');

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->storedTypeOf($order));
    }

    private function setDestinationType(OrderInterface $order, string $type): void
    {
        $this->client->request(
            'PUT',
            sprintf('/api/v2/shop/orders/%s/destination-type', (string) $order->getTokenValue()),
            server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'],
            content: json_encode(['type' => $type], \JSON_THROW_ON_ERROR),
        );
    }

    private function storedTypeOf(OrderInterface $order): ?string
    {
        $this->entityManager->clear();
        $destination = $this->entityManager->getRepository(CarrierOrderDestination::class)->findOneBy(['order' => $order->getId()]);

        return $destination instanceof CarrierOrderDestinationInterface ? $destination->getType() : null;
    }
}
