<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

final readonly class PackagingContext implements Context
{
    /**
     * @param FactoryInterface<CarrierPackageBoxInterface> $boxFactory
     * @param RepositoryInterface<CarrierPackageBoxInterface> $boxRepository
     */
    public function __construct(
        private FactoryInterface $boxFactory,
        private RepositoryInterface $boxRepository,
    ) {
    }

    /**
     * A box of the store's units that holds a mug and takes 30 of them.
     */
    #[Given('the catalog has a :name box')]
    public function theCatalogHasABox(string $name): void
    {
        $box = $this->boxFactory->createNew();
        $box->setName($name);
        $box->setInnerLength(12.0);
        $box->setInnerWidth(10.0);
        $box->setInnerHeight(8.0);
        $box->setOuterLength(13.0);
        $box->setOuterWidth(11.0);
        $box->setOuterHeight(9.0);
        $box->setEmptyWeight(0.5);
        $box->setMaxWeight(30.0);

        $this->boxRepository->add($box);
    }
}
