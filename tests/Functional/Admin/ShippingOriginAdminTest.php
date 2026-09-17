<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShippingOriginAdminTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const FORM = 'jpmmartin_carrier_shipping_origin';

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

        $this->client->loginUser($this->createAdmin('origin-admin'), 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * CA-1: the administrator creates, edits and deletes an origin from the admin.
     */
    public function testTheAdministratorCreatesEditsAndDeletesAnOrigin(): void
    {
        $channel = $this->createChannel('web-admin-origin');
        $this->createCountry('ES');

        $this->submitCreateForm([
            self::FORM . '[channel]' => 'web-admin-origin',
            self::FORM . '[street]' => 'Gran Via 1',
            self::FORM . '[city]' => 'Madrid',
            self::FORM . '[postcode]' => '28013',
            self::FORM . '[countryCode]' => 'ES',
            self::FORM . '[defaultDestinationType]' => 'residential',
            self::FORM . '[weightUnit]' => 'kg',
            self::FORM . '[dimensionUnit]' => 'cm',
            self::FORM . '[maxPackageWeight]' => '68',
        ]);
        self::assertResponseRedirects();

        $origin = $this->findOriginOf($channel);
        self::assertSame('Madrid', $origin->getCity());
        self::assertSame('kg', $origin->getWeightUnit());
        self::assertSame(68.0, $origin->getMaxPackageWeight());
        self::assertSame('residential', $origin->getDefaultDestinationType());
        $id = $origin->getId();

        $crawler = $this->client->request('GET', sprintf('/admin/shipping-origins/%d/edit', $id));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[city]' => 'Barcelona',
        ]));
        self::assertResponseRedirects();

        self::assertSame('Barcelona', $this->findOriginOf($channel)->getCity());

        // Deleted through the confirmation form the index renders, which carries the CSRF token.
        $crawler = $this->client->request('GET', '/admin/shipping-origins/');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[action$="/admin/shipping-origins/%d"]', $id))->form());
        self::assertResponseRedirects();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(CarrierShippingOrigin::class, $id));
    }

    public function testASecondOriginForTheSameChannelIsAFormErrorNotAServerError(): void
    {
        $this->createOrigin($this->createChannel('web-admin-duplicate'));
        $this->createCountry('ES');

        $this->submitCreateForm([
            self::FORM . '[channel]' => 'web-admin-duplicate',
            self::FORM . '[street]' => 'Diagonal 1',
            self::FORM . '[city]' => 'Barcelona',
            self::FORM . '[postcode]' => '08019',
            self::FORM . '[countryCode]' => 'ES',
            self::FORM . '[defaultDestinationType]' => 'residential',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'This channel already has a shipping origin.');
    }

    /**
     * D-16: variant measures are shared by every channel, so every origin declares the same units.
     */
    public function testAnOriginWithUnitsOtherOriginsDoNotUseIsRejected(): void
    {
        $this->createOrigin($this->createChannel('web-units-first'), 'lb', 'in');
        $this->createChannel('web-units-second');
        $this->createCountry('ES');

        $this->submitCreateForm([
            self::FORM . '[channel]' => 'web-units-second',
            self::FORM . '[street]' => 'Diagonal 1',
            self::FORM . '[city]' => 'Barcelona',
            self::FORM . '[postcode]' => '08019',
            self::FORM . '[countryCode]' => 'ES',
            self::FORM . '[defaultDestinationType]' => 'residential',
            self::FORM . '[weightUnit]' => 'kg',
            self::FORM . '[dimensionUnit]' => 'cm',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Every shipping origin must use the same units. The others use lb.');
        self::assertSelectorTextContains('body', 'Every shipping origin must use the same units. The others use in.');
    }

    /**
     * CA-48: the default destination type changes the price, so it has no default either.
     */
    public function testAnOriginWithoutADefaultDestinationTypeIsRejected(): void
    {
        $this->createChannel('web-admin-destination');
        $this->createCountry('ES');

        $this->submitCreateForm([
            self::FORM . '[channel]' => 'web-admin-destination',
            self::FORM . '[street]' => 'Gran Via 1',
            self::FORM . '[city]' => 'Madrid',
            self::FORM . '[postcode]' => '28013',
            self::FORM . '[countryCode]' => 'ES',
            self::FORM . '[defaultDestinationType]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choose whether deliveries go to homes or businesses when the buyer has not said.');
    }

    /**
     * CA-35: an origin can be restricted to part of the catalog from its form.
     */
    public function testTheAdministratorRestrictsAnOriginToSomeBoxes(): void
    {
        $channel = $this->createChannel('web-admin-boxes');
        $this->createCountry('ES');
        $this->createBox('Small');
        $large = $this->createBox('Large');

        $this->submitCreateForm([
            self::FORM . '[channel]' => 'web-admin-boxes',
            self::FORM . '[street]' => 'Gran Via 1',
            self::FORM . '[city]' => 'Madrid',
            self::FORM . '[postcode]' => '28013',
            self::FORM . '[countryCode]' => 'ES',
            self::FORM . '[defaultDestinationType]' => 'residential',
            self::FORM . '[boxes]' => [(string) $large->getId()],
        ]);
        self::assertResponseRedirects();

        $boxes = $this->findOriginOf($channel)->getBoxes();
        self::assertCount(1, $boxes);

        $box = $boxes->first();
        self::assertInstanceOf(CarrierPackageBoxInterface::class, $box);
        self::assertSame('Large', $box->getName());
    }

    /** @param array<string, string|list<string>> $values */
    private function submitCreateForm(array $values): void
    {
        $crawler = $this->client->request('GET', '/admin/shipping-origins/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form($values));
    }

    private function findOriginOf(ChannelInterface $channel): CarrierShippingOrigin
    {
        $this->entityManager->clear();

        $origin = $this->entityManager->getRepository(CarrierShippingOrigin::class)->findOneBy(['channel' => $channel->getId()]);
        self::assertInstanceOf(CarrierShippingOrigin::class, $origin);

        return $origin;
    }
}
