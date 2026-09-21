<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * A label costs money and goes out on the merchant's account, so issuing one is always something an
 * administrator asked for: it is offered as an action of its own, and only for a shipment this plugin sends.
 */
final class IssueCarrierLabelsAdminTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const ISSUE_BUTTON = 'data-test-jpmmartin-carrier-issue-labels-button';

    private const BATCH_BUTTON = 'data-test-jpmmartin-carrier-issue-labels-in-batch-button';

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
        $order = $this->createCarrierOrder($this->channel, 'ups_rate');
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
        $order = $this->createCarrierOrder($this->channel, 'flat_rate');
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
        $shipment = $this->createCarrierOrder($this->channel, 'ups_rate')->getShipments()->first();
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
        $shipment = $this->createCarrierOrder($this->channel, 'ups_rate')->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $this->client->loginUser($this->createAdmin('no-token-admin'), 'admin');

        $this->client->request('POST', '/admin/carrier-shipments/' . $shipment->getId() . '/issue-labels');

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertNull($this->entityManager->getRepository(CarrierShipmentExport::class)->findOneBy(['shipment' => $shipment]));
    }

    /**
     * A morning's worth of labels is printed from the grid, so the action is offered there too.
     */
    public function testTheBatchActionIsOfferedOnTheShipmentsGrid(): void
    {
        $this->createCarrierOrder($this->channel, 'ups_rate');
        $this->client->loginUser($this->createAdmin('batch-grid-admin'), 'admin');

        $this->client->request('GET', '/admin/shipments/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::BATCH_BUTTON, (string) $this->client->getResponse()->getContent());
    }

    public function testWithoutAnAdminSessionNothingIsIssuedInABatchEither(): void
    {
        $shipment = $this->createCarrierOrder($this->channel, 'ups_rate')->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        $this->client->request('POST', '/admin/carrier-shipments/issue-labels', ['ids' => [$shipment->getId()]]);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertNull($this->entityManager->getRepository(CarrierShipmentExport::class)->findOneBy(['shipment' => $shipment]));
    }

    public function testWithoutTheAdminsOwnTokenNothingIsIssuedInABatchEither(): void
    {
        $shipment = $this->createCarrierOrder($this->channel, 'ups_rate')->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $this->client->loginUser($this->createAdmin('batch-no-token-admin'), 'admin');

        $this->client->request('POST', '/admin/carrier-shipments/issue-labels', ['ids' => [$shipment->getId()]]);

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertNull($this->entityManager->getRepository(CarrierShipmentExport::class)->findOneBy(['shipment' => $shipment]));
    }

    /**
     * The summary is the whole point of a batch: saying «done» would hide the shipments nobody printed.
     *
     * No carrier is reached here — there are no credentials stored, so every shipment fails before anything
     * leaves the building — which is exactly the shape of the summary that matters.
     */
    public function testTheBatchSaysHowManyWentOutAndWhyEachOneDidNot(): void
    {
        $first = $this->createCarrierOrder($this->channel, 'ups_rate')->getShipments()->first();
        $second = $this->createCarrierOrder($this->channel, 'ups_rate')->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $first);
        self::assertInstanceOf(ShipmentInterface::class, $second);
        $this->client->loginUser($this->createAdmin('batch-summary-admin'), 'admin');

        $this->client->request('POST', '/admin/carrier-shipments/issue-labels', [
            '_csrf_token' => $this->batchToken(),
            'ids' => [$first->getId(), $second->getId()],
        ]);

        self::assertResponseRedirects();
        $messages = $this->flashes()['error'] ?? [];
        self::assertCount(3, $messages, 'The count, and then one line for each shipment that did not go out.');
        self::assertSame('0 of 2 shipment(s) issued.', $messages[0]);
        self::assertSame(sprintf('Shipment %s: No credentials are stored for the carrier "ups".', $first->getId()), $messages[1]);
        self::assertSame(sprintf('Shipment %s: No credentials are stored for the carrier "ups".', $second->getId()), $messages[2]);
    }

    /**
     * Read from the grid's own form, so the test goes in the way the operator does.
     */
    private function batchToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/shipments/');

        $token = $crawler
            ->filter('form[action="/admin/carrier-shipments/issue-labels"] input[name="_csrf_token"]')
            ->attr('value')
        ;
        self::assertIsString($token);

        return $token;
    }

    /**
     * @return array<string, list<string>>
     */
    private function flashes(): array
    {
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(Session::class, $session);

        /** @var array<string, list<string>> $flashes */
        $flashes = $session->getFlashBag()->all();

        return $flashes;
    }
}
