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
 * The customs data is declared where the variant is edited, not in a screen of its own.
 */
final class CustomsDataAdminTest extends WebTestCase
{
    use AdminFixturesTrait;

    private const FORM = 'sylius_admin_product_variant';

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

        $this->client->loginUser($this->createAdmin('customs-admin'), 'admin');
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testTheAdministratorDeclaresAndChangesTheCustomsDataOfAVariant(): void
    {
        $variant = $this->createVariant('CUSTOMS-ADMIN-MUG');

        $this->submitVariantForm($variant, ['hsCode' => '6912.00.21', 'countryOfOrigin' => 'PT']);
        self::assertResponseRedirects();

        $customsData = $this->customsDataOf($variant);
        self::assertSame('69120021', $customsData->getHsCode());
        self::assertSame('PT', $customsData->getCountryOfOrigin());

        // Editing again changes the same row instead of adding another.
        $this->submitVariantForm($variant, ['hsCode' => '691110', 'countryOfOrigin' => 'CN']);
        self::assertResponseRedirects();

        $customsData = $this->customsDataOf($variant);
        self::assertSame('691110', $customsData->getHsCode());
        self::assertSame('CN', $customsData->getCountryOfOrigin());
        self::assertCount(1, $this->entityManager->getRepository(CarrierCustomsData::class)->findAll());
    }

    public function testACodeCustomsWouldNotRecogniseIsRefused(): void
    {
        $variant = $this->createVariant('CUSTOMS-ADMIN-BAD');

        $this->submitVariantForm($variant, ['hsCode' => '6912AB', 'countryOfOrigin' => 'PT']);

        // The form comes back unprocessable instead of redirecting, and nothing was written.
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->entityManager->getRepository(CarrierCustomsData::class)->findAll());
    }

    /**
     * A variant that never crosses a border needs none of this: the form saves, and the plugin does not fill
     * the catalogue with empty rows to say nothing.
     */
    public function testAVariantWithoutCustomsDataIsSavedAndStoresNothing(): void
    {
        $variant = $this->createVariant('CUSTOMS-ADMIN-EMPTY');

        $this->submitVariantForm($variant, ['hsCode' => '', 'countryOfOrigin' => '']);

        self::assertResponseRedirects();
        self::assertSame([], $this->entityManager->getRepository(CarrierCustomsData::class)->findAll());
    }

    /**
     * @param array{hsCode: string, countryOfOrigin: string} $customsData
     */
    private function submitVariantForm(ProductVariantInterface $variant, array $customsData): void
    {
        $crawler = $this->client->request('GET', sprintf(
            '/admin/products/%s/variants/%s/edit',
            (string) $variant->getProduct()?->getId(),
            (string) $variant->getId(),
        ));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter(sprintf('form[name="%s"]', self::FORM))->form();
        $form[sprintf('%s[%s][hsCode]', self::FORM, 'jpmmartinCarrierCustomsData')] = $customsData['hsCode'];
        $form[sprintf('%s[%s][countryOfOrigin]', self::FORM, 'jpmmartinCarrierCustomsData')] = $customsData['countryOfOrigin'];

        $this->client->submit($form);
    }

    private function customsDataOf(ProductVariantInterface $variant): CarrierCustomsData
    {
        $this->entityManager->clear();
        $customsData = $this->entityManager->getRepository(CarrierCustomsData::class)->findOneBy(['variant' => $variant->getId()]);
        self::assertInstanceOf(CarrierCustomsData::class, $customsData);

        return $customsData;
    }

    private function createVariant(string $code): ProductVariantInterface
    {
        $product = new Product();
        $product->setCode($code);
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mug');
        $product->setSlug('customs-admin-' . strtolower($code));

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
