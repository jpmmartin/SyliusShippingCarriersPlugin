<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use Sylius\Behat\Page\Admin\Crud\IndexPageInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\PackageBox\CreatePage;
use Webmozart\Assert\Assert;

final readonly class ManagingPackageBoxesContext implements Context
{
    /**
     * @param RepositoryInterface<CarrierPackageBoxInterface> $boxRepository
     */
    public function __construct(
        private CreatePage $createPage,
        private IndexPageInterface $indexPage,
        private RepositoryInterface $boxRepository,
    ) {
    }

    #[When('I want to add a new box to the catalog')]
    public function iWantToAddANewBoxToTheCatalog(): void
    {
        $this->createPage->open();
    }

    #[When('I call it :name')]
    public function iCallIt(string $name): void
    {
        $this->createPage->nameIt($name);
    }

    #[When('it holds :length by :width by :height inside')]
    public function itHoldsInside(string $length, string $width, string $height): void
    {
        $this->createPage->specifyInnerMeasures($length, $width, $height);
    }

    #[When('it measures :length by :width by :height outside')]
    public function itMeasuresOutside(string $length, string $width, string $height): void
    {
        $this->createPage->specifyOuterMeasures($length, $width, $height);
    }

    #[When('it weighs :emptyWeight empty and takes :maxWeight at most')]
    public function itWeighsEmptyAndTakesAtMost(string $emptyWeight, string $maxWeight): void
    {
        $this->createPage->specifyWeights($emptyWeight, $maxWeight);
    }

    #[When('I add it to the catalog')]
    public function iAddItToTheCatalog(): void
    {
        $this->createPage->create();
    }

    #[Then('the catalog should have a :name box that holds :length by :width by :height')]
    public function theCatalogShouldHaveABox(string $name, string $length, string $width, string $height): void
    {
        $this->indexPage->open();
        Assert::true($this->indexPage->isSingleResourceOnPage(['name' => $name]));

        $box = $this->boxRepository->findOneBy(['name' => $name]);
        Assert::isInstanceOf($box, CarrierPackageBoxInterface::class);
        Assert::same([$box->getInnerLength(), $box->getInnerWidth(), $box->getInnerHeight()], [(float) $length, (float) $width, (float) $height]);
    }

    #[Then('I should be told that the carriers do not take a box that long')]
    public function iShouldBeToldThatTheCarriersDoNotTakeABoxThatLong(): void
    {
        Assert::contains($this->createPage->getValidationMessage('outer_length'), 'Carriers do not accept a box this long');
    }

    #[Then('the catalog should be empty')]
    public function theCatalogShouldBeEmpty(): void
    {
        Assert::count($this->boxRepository->findAll(), 0);
    }
}
