<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PackageBoxAdminTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const FORM = 'jpmmartin_carrier_package_box';

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

        $this->client->loginUser($this->createAdmin('box-admin'), 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * CA-34: the administrator creates, edits and deletes boxes from the admin.
     */
    public function testTheAdministratorCreatesEditsAndDeletesABox(): void
    {
        $this->submitCreateForm($this->boxValues('Medium', 13.0, 11.0, 9.0));
        self::assertResponseRedirects();

        $box = $this->findBox('Medium');
        self::assertSame(12.0, $box->getInnerLength());
        self::assertSame(13.0, $box->getOuterLength());
        self::assertSame(0.5, $box->getEmptyWeight());
        $id = $box->getId();

        $crawler = $this->client->request('GET', sprintf('/admin/package-boxes/%d/edit', $id));
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form([
            self::FORM . '[name]' => 'Medium box',
        ]));
        self::assertResponseRedirects();
        self::assertSame(13.0, $this->findBox('Medium box')->getOuterLength());

        // Deleted through the confirmation form the index renders, which carries the CSRF token.
        $crawler = $this->client->request('GET', '/admin/package-boxes/');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[action$="/admin/package-boxes/%d"]', $id))->form());
        self::assertResponseRedirects();

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(CarrierPackageBox::class, $id));
    }

    /**
     * The verification T-10 names: a store in inches rejects a box 120" long.
     */
    public function testABoxLongerThanCarriersAcceptIsRejected(): void
    {
        $this->submitCreateForm($this->boxValues('Too long', 120.0, 10.0, 10.0));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'The longest outer side can be at most 108 in.');
    }

    public function testABoxWhoseLengthPlusGirthIsTooLargeIsRejected(): void
    {
        // 100 + 2 × (20 + 20) = 180, over 165.
        $this->submitCreateForm($this->boxValues('Too large', 100.0, 20.0, 20.0));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'plus the girth around the other two can be at most 165 in.');
    }

    /**
     * CA-41: the box is read in the store's units. 120 cm is about 47", well within the limits.
     */
    public function testA120CentimetreBoxIsAcceptedInAStoreInCentimetres(): void
    {
        $this->createOrigin($this->createChannel('web-metric'), 'kg', 'cm');

        $crawler = $this->client->request('GET', '/admin/package-boxes/new');
        self::assertSelectorTextContains('body', 'Outer length (cm)');

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form(
            $this->boxValues('Metric', 120.0, 30.0, 30.0),
        ));

        self::assertResponseRedirects();
        self::assertSame(120.0, $this->findBox('Metric')->getOuterLength());
    }

    /** @return array<string, string> */
    private function boxValues(string $name, float $outerLength, float $outerWidth, float $outerHeight): array
    {
        return [
            self::FORM . '[name]' => $name,
            self::FORM . '[innerLength]' => '12',
            self::FORM . '[innerWidth]' => '10',
            self::FORM . '[innerHeight]' => '8',
            self::FORM . '[outerLength]' => (string) $outerLength,
            self::FORM . '[outerWidth]' => (string) $outerWidth,
            self::FORM . '[outerHeight]' => (string) $outerHeight,
            self::FORM . '[emptyWeight]' => '0.5',
            self::FORM . '[maxWeight]' => '30',
        ];
    }

    /** @param array<string, string> $values */
    private function submitCreateForm(array $values): void
    {
        $crawler = $this->client->request('GET', '/admin/package-boxes/new');
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter(sprintf('form[name="%s"]', self::FORM))->form($values));
    }

    private function findBox(string $name): CarrierPackageBox
    {
        $this->entityManager->clear();

        $box = $this->entityManager->getRepository(CarrierPackageBox::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(CarrierPackageBox::class, $box);

        return $box;
    }
}
