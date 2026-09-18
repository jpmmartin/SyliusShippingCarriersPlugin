<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Entity;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsData;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CarrierCustomsDataTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Everything each test writes is rolled back, so tests do not depend on each other's leftovers.
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testItPersistsTheCustomsDataOfAVariant(): void
    {
        $variant = $this->createVariant('CUSTOMS-MUG');

        $customsData = new CarrierCustomsData();
        $customsData->setVariant($variant);
        $customsData->setHsCode('691200');
        $customsData->setCountryOfOrigin('PT');

        $this->entityManager->persist($customsData);
        $this->entityManager->flush();
        self::assertNotNull($customsData->getId());

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(CarrierCustomsData::class)->find($customsData->getId());

        self::assertInstanceOf(CarrierCustomsData::class, $stored);
        self::assertSame('691200', $stored->getHsCode());
        self::assertSame('PT', $stored->getCountryOfOrigin());
        self::assertSame('CUSTOMS-MUG', $stored->getVariant()?->getCode());
    }

    public function testAVariantCannotHaveTwoSetsOfCustomsData(): void
    {
        $variant = $this->createVariant('CUSTOMS-TWICE');

        foreach (['691200', '691110'] as $hsCode) {
            $customsData = new CarrierCustomsData();
            $customsData->setVariant($variant);
            $customsData->setHsCode($hsCode);
            $customsData->setCountryOfOrigin('PT');
            $this->entityManager->persist($customsData);
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    /**
     * Customs data is incomplete until it says both what the goods are and where they were made. This is what
     * the grid of variants missing their customs data reads.
     */
    public function testItIsOnlyCompleteWithBothTheCodeAndTheCountry(): void
    {
        $customsData = new CarrierCustomsData();
        self::assertFalse($customsData->isComplete());

        $customsData->setHsCode('691200');
        self::assertFalse($customsData->isComplete());

        $customsData->setCountryOfOrigin('PT');
        self::assertTrue($customsData->isComplete());
    }

    /**
     * Two catalogues that punctuate the same code differently must not end up declaring two different things,
     * so the separators are dropped on the way in.
     */
    public function testTheCodeIsKeptAsTheDigitsItIsWhateverSeparatorsWereTyped(): void
    {
        $customsData = new CarrierCustomsData();

        foreach (['6912.00.21', '6912 00 21', '6912-00-21', ' 69120021 '] as $typed) {
            $customsData->setHsCode($typed);
            self::assertSame('69120021', $customsData->getHsCode());
        }

        $customsData->setHsCode('   ');
        self::assertNull($customsData->getHsCode());
    }

    /**
     * The decision that makes the plugin live alongside others: overriding a Sylius model is exclusive, so
     * two plugins that both did it could not be installed together. The customs data lives in a table of its
     * own, and `ProductVariant` stays Sylius's.
     */
    public function testSyliusProductVariantIsNotOverriddenByThePlugin(): void
    {
        $variantClass = self::getContainer()->getParameter('sylius.model.product_variant.class');

        self::assertIsString($variantClass);
        self::assertStringStartsNotWith('JpmMartin\\', $variantClass);
        self::assertTrue(class_exists($variantClass));

        /** @var class-string<object> $className */
        $className = $variantClass;
        $metadata = $this->entityManager->getClassMetadata($className);
        self::assertSame(ProductVariant::class, $metadata->getName());

        // And nothing of the plugin was mapped onto the variant's table either.
        $columns = $metadata->getColumnNames();
        self::assertNotContains('hs_code', $columns);
        self::assertNotContains('country_of_origin', $columns);
    }

    private function createVariant(string $code): ProductVariantInterface
    {
        $product = new Product();
        $product->setCode($code);
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');
        $product->setName('Mug');
        $product->setSlug('customs-mug-' . strtolower($code));

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
