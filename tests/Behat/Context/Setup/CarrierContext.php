<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\FailurePolicy;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\CoreBundle\Fixture\Factory\ExampleFactoryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier\FakeCarrierState;
use Webmozart\Assert\Assert;

final readonly class CarrierContext implements Context
{
    /**
     * @param FactoryInterface<CarrierCredentialsInterface> $credentialsFactory
     * @param RepositoryInterface<CarrierCredentialsInterface> $credentialsRepository
     * @param ExampleFactoryInterface<ShippingMethodInterface> $shippingMethodExampleFactory
     * @param RepositoryInterface<ShippingMethodInterface> $shippingMethodRepository
     */
    public function __construct(
        private SharedStorageInterface $sharedStorage,
        private FactoryInterface $credentialsFactory,
        private RepositoryInterface $credentialsRepository,
        private ExampleFactoryInterface $shippingMethodExampleFactory,
        private RepositoryInterface $shippingMethodRepository,
        private FakeCarrierState $fakeCarrierState,
    ) {
    }

    /**
     * Sandbox credentials of an account whose packages are picked up on a schedule.
     */
    #[Given('/^the store has credentials for (UPS|FedEx)$/')]
    public function theStoreHasCredentialsFor(string $carrierName): void
    {
        $credentials = $this->credentialsFactory->createNew();
        $credentials->setCarrier(self::carrierCode($carrierName));
        $credentials->setEnvironment(CarrierCredentialsInterface::ENVIRONMENT_SANDBOX);
        $credentials->setPickupType(CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED);
        $credentials->setCredentials([
            CarrierCredentialsInterface::CLIENT_ID => 'behat-client-id',
            CarrierCredentialsInterface::CLIENT_SECRET => 'behat-client-secret',
            CarrierCredentialsInterface::ACCOUNT_NUMBER => 'BEHAT1',
        ]);

        $this->credentialsRepository->add($credentials);
    }

    /**
     * The shipping method hides when its carrier does not answer, in every zone the store ships to.
     */
    #[Given('/^the store has "([^"]+)" shipping method for (UPS|FedEx) service "([^"]+)"$/')]
    public function theStoreHasShippingMethodForService(string $shippingMethodName, string $carrierName, string $serviceCode): void
    {
        $channel = $this->sharedStorage->get('channel');
        Assert::isInstanceOf($channel, ChannelInterface::class);
        $zone = $this->sharedStorage->get('zone');
        Assert::isInstanceOf($zone, ZoneInterface::class);

        $shippingMethod = $this->shippingMethodExampleFactory->create([
            'name' => $shippingMethodName,
            'enabled' => true,
            'zone' => $zone,
            'channels' => [$channel],
            'calculator' => [
                'type' => self::carrierCode($carrierName) . '_rate',
                'configuration' => [
                    'service' => $serviceCode,
                    'failure_policy' => FailurePolicy::HIDE,
                ],
            ],
        ]);

        $this->shippingMethodRepository->add($shippingMethod);
        $this->sharedStorage->set('shipping_method', $shippingMethod);
    }

    #[Given('/^(UPS|FedEx) rates service "([^"]+)" at "\$(\d+(?:\.\d{1,2})?)"$/')]
    public function theCarrierRatesServiceAt(string $carrierName, string $serviceCode, string $amount): void
    {
        $this->fakeCarrierState->rateService(self::carrierCode($carrierName), $serviceCode, (int) round((float) $amount * 100), 'USD');
    }

    private static function carrierCode(string $carrierName): string
    {
        return 'FedEx' === $carrierName ? CarrierCredentialsInterface::CARRIER_FEDEX : CarrierCredentialsInterface::CARRIER_UPS;
    }
}
