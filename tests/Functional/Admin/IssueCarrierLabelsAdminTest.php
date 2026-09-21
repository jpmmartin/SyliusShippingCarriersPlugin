<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A label costs money and goes out on the merchant's account, so issuing one is always something an
 * administrator asked for: it is offered as an action of its own, and only for a shipment this plugin sends.
 */
final class IssueCarrierLabelsAdminTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const ISSUE_BUTTON = 'data-test-jpmmartin-carrier-issue-labels-button';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private ChannelInterface $channel;

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

        $this->channel = $this->createChannel('LABELS_WEB');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testTheActionIsOfferedForAShipmentOfACarrierOfThisPlugin(): void
    {
        $order = $this->createOrder('ups_rate');
        $this->client->loginUser($this->createAdmin('issue-labels-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::ISSUE_BUTTON, (string) $this->client->getResponse()->getContent());
    }

    /**
     * A shipment this plugin does not send has nothing to issue, so nothing is offered for it.
     */
    public function testTheActionIsNotOfferedForAShipmentOfAnotherShippingMethod(): void
    {
        $order = $this->createOrder('flat_rate');
        $this->client->loginUser($this->createAdmin('foreign-method-admin'), 'admin');

        $this->client->request('GET', '/admin/orders/' . $order->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::ISSUE_BUTTON, (string) $this->client->getResponse()->getContent());
    }

    /**
     * The one that matters: nothing is issued on the merchant's account without an administrator behind it.
     */
    public function testWithoutAnAdminSessionNothingIsIssued(): void
    {
        $shipment = $this->createOrder('ups_rate')->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        $this->client->request('POST', '/admin/carrier-shipments/' . $shipment->getId() . '/issue-labels');

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertNull($this->entityManager->getRepository(CarrierShipmentExport::class)->findOneBy(['shipment' => $shipment]));
    }

    /**
     * Without the admin's own token the request did not come from the admin, whoever is logged in.
     */
    public function testWithoutTheAdminsOwnTokenNothingIsIssued(): void
    {
        $shipment = $this->createOrder('ups_rate')->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $this->client->loginUser($this->createAdmin('no-token-admin'), 'admin');

        $this->client->request('POST', '/admin/carrier-shipments/' . $shipment->getId() . '/issue-labels');

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertNull($this->entityManager->getRepository(CarrierShipmentExport::class)->findOneBy(['shipment' => $shipment]));
    }

    private function createOrder(string $calculator): OrderInterface
    {
        $order = new Order();
        $order->setChannel($this->channel);
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('en_US');
        // The admin refuses to show a cart: findOrderById leaves that state out.
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCustomer($this->createCustomer());

        $shipment = new Shipment();
        $shipment->setMethod($this->createShippingMethod($calculator));
        $order->addShipment($shipment);

        $this->entityManager->persist($order);
        $this->entityManager->persist($shipment);
        $this->entityManager->flush();

        return $order;
    }

    private function createCustomer(): CustomerInterface
    {
        $customer = new Customer();
        $customer->setEmail(sprintf('buyer-%s@example.com', bin2hex(random_bytes(4))));
        $customer->setFirstName('Grace');
        $customer->setLastName('Hopper');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function createShippingMethod(string $calculator): ShippingMethodInterface
    {
        $zone = new Zone();
        $zone->setCode('labels-zone-' . bin2hex(random_bytes(4)));
        $zone->setName('Labels zone');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);

        $method = new ShippingMethod();
        $method->setCode('labels-method-' . bin2hex(random_bytes(4)));
        $method->setCurrentLocale('en_US');
        $method->setFallbackLocale('en_US');
        $method->setName('Labels method');
        $method->setCalculator($calculator);
        $method->setConfiguration('flat_rate' === $calculator ? ['LABELS_WEB' => ['amount' => 500]] : ['service' => '03']);
        $method->setZone($zone);

        $this->entityManager->persist($zone);
        $this->entityManager->persist($method);
        $this->entityManager->flush();

        return $method;
    }
}
