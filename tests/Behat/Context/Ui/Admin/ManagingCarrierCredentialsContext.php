<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\CarrierCredentials\CreatePage;
use Webmozart\Assert\Assert;

final readonly class ManagingCarrierCredentialsContext implements Context
{
    /**
     * @param RepositoryInterface<CarrierCredentialsInterface> $credentialsRepository
     */
    public function __construct(
        private CreatePage $createPage,
        private RepositoryInterface $credentialsRepository,
    ) {
    }

    #[When('/^I want to give the store the credentials of (UPS|FedEx)$/')]
    public function iWantToGiveTheStoreTheCredentialsOf(string $carrierName): void
    {
        $this->createPage->open();
        $this->createPage->chooseCarrier($carrierName);
    }

    #[When('I use its :environment with the client :clientId and the secret :clientSecret')]
    public function iUseItsEnvironmentWith(string $environment, string $clientId, string $clientSecret): void
    {
        $this->createPage->chooseEnvironment($environment);
        $this->createPage->specifyCredentials($clientId, $clientSecret);
    }

    #[When('I hand it the packages by :pickupType')]
    public function iHandItThePackagesBy(string $pickupType): void
    {
        $this->createPage->choosePickupType($pickupType);
    }

    #[When('I add them')]
    public function iAddThem(): void
    {
        $this->createPage->create();
    }

    #[Then('/^(UPS|FedEx) should be called against its (sandbox|production), with packages picked up by "([^"]+)"$/')]
    public function theCarrierShouldBeCalledAgainstIts(string $carrierName, string $environment, string $pickupType): void
    {
        $credentials = $this->onlyCredentials();

        Assert::same($credentials->getCarrier(), 'FedEx' === $carrierName ? 'fedex' : 'ups');
        Assert::same($credentials->getEnvironment(), $environment);
        Assert::same($credentials->getPickupType(), $pickupType);
    }

    #[Then('I should be told to choose between the sandbox and production')]
    public function iShouldBeToldToChooseBetweenTheSandboxAndProduction(): void
    {
        Assert::contains($this->createPage->getValidationMessage('environment'), 'Choose whether these credentials go against the sandbox or production');
    }

    #[Then('I should be told to say how the packages reach the carrier')]
    public function iShouldBeToldToSayHowThePackagesReachTheCarrier(): void
    {
        Assert::contains($this->createPage->getValidationMessage('pickup_type'), 'Choose how packages reach the carrier');
    }

    #[Then('the store should have no carrier credentials')]
    public function theStoreShouldHaveNoCarrierCredentials(): void
    {
        Assert::count($this->credentialsRepository->findAll(), 0);
    }

    private function onlyCredentials(): CarrierCredentialsInterface
    {
        $credentials = $this->credentialsRepository->findAll();
        Assert::count($credentials, 1);
        Assert::isInstanceOf($credentials[0], CarrierCredentialsInterface::class);

        return $credentials[0];
    }
}
