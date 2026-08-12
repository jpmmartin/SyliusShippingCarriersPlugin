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
     * The criterion this whole task exists for (CA-2): one origin per channel.
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

        return $origin;
    }
}
