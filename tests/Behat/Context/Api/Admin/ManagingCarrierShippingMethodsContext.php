<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Api\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\FailurePolicy;
use Sylius\Behat\Client\ApiClientInterface;
use Sylius\Behat\Client\ResponseCheckerInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Webmozart\Assert\Assert;

/**
 * Setting up a carrier's shipping method through the admin API, where the whole configuration travels in one
 * request.
 */
final class ManagingCarrierShippingMethodsContext implements Context
{
    /** @var array<string, mixed> */
    /** @var array{service?: string, failure_policy?: string, flat_amount?: array<string, int>} */
    private array $configuration = [];

    /**
     * @param RepositoryInterface<ShippingMethodInterface> $shippingMethodRepository
     */
    public function __construct(
        private readonly ApiClientInterface $client,
        private readonly ResponseCheckerInterface $responseChecker,
        private readonly RepositoryInterface $shippingMethodRepository,
    ) {
    }

    #[When('/^I rate it with (UPS|FedEx) service "([^"]+)"$/')]
    public function iRateItWithCarrierService(string $carrierName, string $serviceCode): void
    {
        $this->configuration = ['service' => $serviceCode, 'failure_policy' => FailurePolicy::HIDE];

        $this->client->addRequestData('calculator', self::carrierCode($carrierName) . '_rate');
        $this->client->addRequestData('configuration', $this->configuration);
    }

    #[When('/^I charge "\$(\d+(?:\.\d{1,2})?)" for it in the "([^"]+)" channel when the carrier fails$/')]
    public function iChargeForItWhenTheCarrierFails(string $amount, string $channelCode): void
    {
        $this->configuration['failure_policy'] = FailurePolicy::FLAT;
        $flatAmounts = $this->configuration['flat_amount'] ?? [];
        $flatAmounts[$channelCode] = (int) round((float) $amount * 100);
        $this->configuration['flat_amount'] = $flatAmounts;

        $this->client->addRequestData('configuration', $this->configuration);
    }

    #[When('I hide it when the carrier fails')]
    public function iHideItWhenTheCarrierFails(): void
    {
        $this->configuration['failure_policy'] = FailurePolicy::HIDE;

        $this->client->addRequestData('configuration', $this->configuration);
    }

    #[Then('/^the "([^"]+)" shipping method should be rated with (UPS|FedEx) service "([^"]+)"$/')]
    public function theShippingMethodShouldBeRatedWithService(string $code, string $carrierName, string $serviceCode): void
    {
        $shippingMethod = $this->shippingMethodRepository->findOneBy(['code' => $code]);
        Assert::isInstanceOf($shippingMethod, ShippingMethodInterface::class);
        Assert::same($shippingMethod->getCalculator(), self::carrierCode($carrierName) . '_rate');
        /** @var array{service?: string} $configuration */
        $configuration = $shippingMethod->getConfiguration();
        Assert::same($configuration['service'] ?? null, $serviceCode);
    }

    #[Then('/^it should charge "\$(\d+(?:\.\d{1,2})?)" in the "([^"]+)" channel when the carrier fails$/')]
    public function itShouldChargeInTheChannelWhenTheCarrierFails(string $amount, string $channelCode): void
    {
        $shippingMethod = $this->shippingMethodRepository->findOneBy(['calculator' => ['ups_rate', 'fedex_rate']]);
        Assert::isInstanceOf($shippingMethod, ShippingMethodInterface::class);

        /** @var array{failure_policy?: string, flat_amount?: array<string, int>} $configuration */
        $configuration = $shippingMethod->getConfiguration();
        Assert::same($configuration['failure_policy'] ?? null, FailurePolicy::FLAT);
        Assert::same($configuration['flat_amount'][$channelCode] ?? null, (int) round((float) $amount * 100));
    }

    #[Then('I should be told that the service is not one of that carrier\'s')]
    public function iShouldBeToldThatTheServiceIsNotOneOfThatCarriers(): void
    {
        Assert::contains($this->error(), 'Choose one of the services of this carrier.');
    }

    #[Then('/^I should be told that the "([^"]+)" channel needs a flat amount$/')]
    public function iShouldBeToldThatTheChannelNeedsAFlatAmount(string $channelCode): void
    {
        Assert::contains($this->error(), sprintf('Enter the flat amount for the channel %s.', $channelCode));
    }

    #[Then('there should be no carrier shipping method')]
    public function thereShouldBeNoCarrierShippingMethod(): void
    {
        Assert::count($this->shippingMethodRepository->findBy(['calculator' => ['ups_rate', 'fedex_rate']]), 0);
    }

    private function error(): string
    {
        return (string) $this->responseChecker->getError($this->client->getLastResponse());
    }

    private static function carrierCode(string $carrierName): string
    {
        return 'FedEx' === $carrierName ? CarrierCredentialsInterface::CARRIER_FEDEX : CarrierCredentialsInterface::CARRIER_UPS;
    }
}
