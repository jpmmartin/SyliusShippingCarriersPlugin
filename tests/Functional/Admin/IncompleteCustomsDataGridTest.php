<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Functional\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsData;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A catalogue of thousands of variants cannot be audited one by one: this is the list of the ones customs
 * could not be told about.
 */
final class IncompleteCustomsDataGridTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const PATH = '/admin/incomplete-customs-data';

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

        $this->client->loginUser($this->createAdmin('incomplete-customs-admin'), 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * The four ways a variant can be missing what customs needs, against one that has everything.
     */
    public function testOnlyTheVariantsCustomsCouldNotBeToldAboutAreListed(): void
    {
        $this->createVariant('CUSTOMS-NONE');
        $this->customsDataFor($this->createVariant('CUSTOMS-NO-CODE'), null, 'PT');
        $this->customsDataFor($this->createVariant('CUSTOMS-NO-COUNTRY'), '691200', null);
        $this->customsDataFor($this->createVariant('CUSTOMS-EMPTY-COUNTRY'), '691200', '');
        $this->customsDataFor($this->createVariant('CUSTOMS-COMPLETE'), '691200', 'PT');

        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        $listed = $crawler->filter('table')->text();

        self::assertStringContainsString('CUSTOMS-NONE', $listed);
        self::assertStringContainsString('CUSTOMS-NO-CODE', $listed);
        self::assertStringContainsString('CUSTOMS-NO-COUNTRY', $listed);
        self::assertStringContainsString('CUSTOMS-EMPTY-COUNTRY', $listed);
        self::assertStringNotContainsString('CUSTOMS-COMPLETE', $listed);
    }

    /**
     * A catalogue with nothing missing says so instead of showing an empty table by accident.
     */
    public function testACatalogueWithEverythingDeclaredListsNothing(): void
    {
        $this->customsDataFor($this->createVariant('CUSTOMS-ALL-GOOD'), '691200', 'PT');

        $crawler = $this->client->request('GET', self::PATH);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('CUSTOMS-ALL-GOOD', $crawler->filter('body')->text());
    }

    /**
     * The plugin adds a list of its own; Sylius's own variant index keeps its route and its contents.
     */
    public function testSyliusOwnVariantIndexIsUntouched(): void
    {
        $variant = $this->createVariant('CUSTOMS-SYLIUS-INDEX');

        $crawler = $this->client->request('GET', sprintf('/admin/products/%s/variants/', (string) $variant->getProduct()?->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('CUSTOMS-SYLIUS-INDEX', $crawler->filter('body')->text());
    }

    private function customsDataFor(ProductVariantInterface $variant, ?string $hsCode, ?string $countryOfOrigin): void
    {
        $customsData = new CarrierCustomsData();
        $customsData->setVariant($variant);
        $customsData->setHsCode($hsCode);
        $customsData->setCountryOfOrigin($countryOfOrigin);

        $this->entityManager->persist($customsData);
        $this->entityManager->flush();
    }

    private function createVariant(string $code): ProductVariantInterface
    {
        $product = new Product();
        $product->setCode($code);
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mug');
        $product->setSlug('incomplete-customs-' . strtolower($code));

        $variant = new ProductVariant();
        $variant->setCode($code);
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $product->addVariant($variant);

        $this->entityManager->persist($product);
        $this->entityManager->persist($variant);
        $this->entityManager->flush();

        return $variant;
    }
}
