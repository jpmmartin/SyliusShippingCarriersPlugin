<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Entity;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CarrierShippingOriginTest extends KernelTestCase
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
        // Everything each test writes is rolled back, so tests do not depend on
        // each other's leftovers nor on the order they run in.
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * 150 lb is the same limit as 68 kg: the lowest maximum package weight the
     * supported carriers publish. Only the unit it is expressed in differs.
     */
    public function testItDefaultsToImperialUnitsAndTheLowestCarrierWeightLimit(): void
    {
        $origin = new CarrierShippingOrigin();

        self::assertSame(CarrierShippingOriginInterface::WEIGHT_UNIT_LB, $origin->getWeightUnit());
        self::assertSame(CarrierShippingOriginInterface::DIMENSION_UNIT_IN, $origin->getDimensionUnit());
        self::assertSame(
            CarrierShippingOriginInterface::DEFAULT_MAX_PACKAGE_WEIGHT_LB,
            $origin->getMaxPackageWeight(),
        );
        // No default destination type: the administrator always chooses it.
        self::assertNull($origin->getDefaultDestinationType());
    }

    /**
     * The one the default was getting wrong: 150 read as kilograms is more than twice what either carrier
     * accepts. An origin in kilograms whose maximum nobody set gets the same limit in kilograms.
     */
    public function testAnOriginInKilogramsDefaultsToTheSameLimitInKilograms(): void
    {
        $origin = $this->createOrigin($this->createChannel('web-kilograms'));
        $origin->setWeightUnit(CarrierShippingOriginInterface::WEIGHT_UNIT_KG);

        $this->entityManager->persist($origin);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->getRepository(CarrierShippingOrigin::class)->find((int) $origin->getId());
        self::assertInstanceOf(CarrierShippingOriginInterface::class, $stored);
        self::assertSame(CarrierShippingOriginInterface::DEFAULT_MAX_PACKAGE_WEIGHT_KG, $stored->getMaxPackageWeight());
    }

    public function testTheDefaultLimitGoesBackToPoundsWithTheUnit(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setWeightUnit(CarrierShippingOriginInterface::WEIGHT_UNIT_KG);
        $origin->setWeightUnit(CarrierShippingOriginInterface::WEIGHT_UNIT_LB);

        self::assertSame(CarrierShippingOriginInterface::DEFAULT_MAX_PACKAGE_WEIGHT_LB, $origin->getMaxPackageWeight());
    }

    /**
     * A maximum somebody chose is theirs: changing the unit does not rewrite it.
     */
    public function testAMaximumSomebodyChoseIsKeptWhenTheUnitChanges(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setMaxPackageWeight(50.0);
        $origin->setWeightUnit(CarrierShippingOriginInterface::WEIGHT_UNIT_KG);

        self::assertSame(50.0, $origin->getMaxPackageWeight());
    }

    /**
     * Without a sender nothing prints: a carrier refuses a label with no name and no phone to return it to.
     */
    public function testItKeepsWhoTheParcelsAreSentBy(): void
    {
        $origin = $this->createOrigin($this->createChannel('web-sender'));
        $origin->setCompanyName('Swaypc');
        $origin->setContactName('Juan Pablo Moreno Martin');
        $origin->setPhone('13057800955');

        $this->entityManager->persist($origin);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->getRepository(CarrierShippingOrigin::class)->find((int) $origin->getId());
        self::assertInstanceOf(CarrierShippingOrigin::class, $stored);
        self::assertSame('Swaypc', $stored->getCompanyName());
        self::assertSame('Juan Pablo Moreno Martin', $stored->getContactName());
        self::assertSame('13057800955', $stored->getPhone());
        self::assertTrue($stored->hasContact());
    }

    /**
     * An origin created before labels existed keeps working; what it cannot do is print one, and it says so
     * instead of letting the carrier say it.
     */
    public function testAnOriginWithoutASenderSaysItCannotPrintALabel(): void
    {
        $origin = new CarrierShippingOrigin();
        self::assertFalse($origin->hasContact());

        $origin->setCompanyName('Swaypc');
        self::assertFalse($origin->hasContact());

        $origin->setContactName('Juan Pablo Moreno Martin');
        self::assertFalse($origin->hasContact());

        $origin->setPhone('13057800955');
        self::assertTrue($origin->hasContact());
    }

    public function testItPersistsAnOrigin(): void
    {
        $origin = $this->createOrigin($this->createChannel('web-persist'));

        $this->entityManager->persist($origin);
        $this->entityManager->flush();

        self::assertNotNull($origin->getId());

        $this->entityManager->clear();

        $found = $this->entityManager->find(CarrierShippingOrigin::class, $origin->getId());

        self::assertNotNull($found);
        self::assertSame('Gran Via 1', $found->getStreet());
        self::assertSame('ES', $found->getCountryCode());
    }

    /**
     * One origin per channel.
     *
     * It asserts the database rejects the second row, not that some PHP guard
     * does. A unique constraint that only lives in the mapping is not a
     * constraint.
     */
    public function testItRejectsASecondOriginForTheSameChannel(): void
    {
        $channel = $this->createChannel('web-unique');

        $this->entityManager->persist($this->createOrigin($channel));
        $this->entityManager->flush();

        $this->entityManager->persist($this->createOrigin($channel));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    /**
     * A Sylius channel cannot exist without a default locale and a base currency:
     * both columns are NOT NULL in sylius_channel.
     */
    private function createChannel(string $code): ChannelInterface
    {
        $locale = new Locale();
        $locale->setCode('en_US');

        $currency = new Currency();
        $currency->setCode('EUR');

        $channel = new Channel();
        $channel->setCode($code);
        $channel->setName($code);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setTaxCalculationStrategy('order_items_based');

        $this->entityManager->persist($locale);
        $this->entityManager->persist($currency);
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    private function createOrigin(ChannelInterface $channel): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setChannel($channel);
        $origin->setStreet('Gran Via 1');
        $origin->setCity('Madrid');
        $origin->setPostcode('28013');
        $origin->setCountryCode('ES');
        $origin->setDefaultDestinationType('residential');

        return $origin;
    }
}
