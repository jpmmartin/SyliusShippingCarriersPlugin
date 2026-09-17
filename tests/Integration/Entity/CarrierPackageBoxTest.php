<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Entity;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierPackageBoxRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Locale\Model\Locale;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CarrierPackageBoxTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var CarrierPackageBoxRepositoryInterface<CarrierPackageBoxInterface> */
    private CarrierPackageBoxRepositoryInterface $boxRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $boxRepository = self::getContainer()->get('jpmmartin_carrier.repository.package_box');
        self::assertInstanceOf(CarrierPackageBoxRepositoryInterface::class, $boxRepository);
        $this->boxRepository = $boxRepository;

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /**
     * A box keeps its inner and outer measures apart, plus its tare and its maximum weight.
     */
    public function testItPersistsABoxWithSeparateInnerAndOuterMeasures(): void
    {
        $box = $this->createBox('Medium', 30.0);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $this->entityManager->find(CarrierPackageBox::class, $box->getId());

        self::assertInstanceOf(CarrierPackageBox::class, $found);
        self::assertSame(30.0, $found->getInnerLength());
        self::assertSame(31.0, $found->getOuterLength());
        self::assertSame(0.4, $found->getEmptyWeight());
        self::assertSame(20.0, $found->getMaxWeight());
    }

    /**
     * An origin without assignments uses the whole catalog.
     */
    public function testAnOriginWithoutAssignmentsSeesTheWholeCatalog(): void
    {
        $small = $this->createBox('Small', 20.0);
        $large = $this->createBox('Large', 50.0);
        $origin = $this->createOrigin('web-whole-catalog');

        $this->entityManager->flush();

        self::assertSame([$small, $large], $this->boxRepository->findApplicableToOrigin($origin));
    }

    /**
     * An origin restricted to part of the catalog only sees that part.
     */
    public function testAnOriginRestrictedToSomeBoxesOnlySeesThose(): void
    {
        $this->createBox('Small', 20.0);
        $large = $this->createBox('Large', 50.0);
        $origin = $this->createOrigin('web-restricted');
        $origin->addBox($large);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(CarrierShippingOrigin::class, $origin->getId());
        self::assertInstanceOf(CarrierShippingOrigin::class, $reloaded);

        $applicable = $this->boxRepository->findApplicableToOrigin($reloaded);

        self::assertCount(1, $applicable);
        self::assertSame('Large', $applicable[0]->getName());
    }

    public function testRemovingABoxRemovesItsAssignmentButNotTheOrigin(): void
    {
        $box = $this->createBox('Small', 20.0);
        $origin = $this->createOrigin('web-remove-box');
        $origin->addBox($box);
        $this->entityManager->flush();

        $this->entityManager->remove($box);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(CarrierShippingOrigin::class, $origin->getId());
        self::assertInstanceOf(CarrierShippingOrigin::class, $reloaded);
        self::assertCount(0, $reloaded->getBoxes());
    }

    private function createBox(string $name, float $innerLength): CarrierPackageBox
    {
        $box = new CarrierPackageBox();
        $box->setName($name);
        $box->setInnerLength($innerLength);
        $box->setInnerWidth(20.0);
        $box->setInnerHeight(10.0);
        $box->setOuterLength($innerLength + 1.0);
        $box->setOuterWidth(21.0);
        $box->setOuterHeight(11.0);
        $box->setEmptyWeight(0.4);
        $box->setMaxWeight(20.0);

        $this->entityManager->persist($box);

        return $box;
    }

    /**
     * A Sylius channel cannot exist without a default locale and a base currency: both columns are
     * NOT NULL in sylius_channel.
     */
    private function createOrigin(string $channelCode): CarrierShippingOrigin
    {
        $locale = new Locale();
        $locale->setCode('en_US');

        $currency = new Currency();
        $currency->setCode('EUR');

        $channel = new Channel();
        $channel->setCode($channelCode);
        $channel->setName($channelCode);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setTaxCalculationStrategy('order_items_based');

        $origin = new CarrierShippingOrigin();
        $origin->setDefaultDestinationType('residential');
        $origin->setChannel($channel);
        $origin->setStreet('Gran Via 1');
        $origin->setCity('Madrid');
        $origin->setPostcode('28013');
        $origin->setCountryCode('ES');

        $this->entityManager->persist($locale);
        $this->entityManager->persist($currency);
        $this->entityManager->persist($channel);
        $this->entityManager->persist($origin);

        return $origin;
    }
}
