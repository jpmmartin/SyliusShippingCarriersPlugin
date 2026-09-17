<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\FailurePolicy;
use Psr\Cache\CacheItemPoolInterface;
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
        private CacheItemPoolInterface $rateCache,
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
        $this->createShippingMethod($shippingMethodName, $carrierName, [
            'service' => $serviceCode,
            'failure_policy' => FailurePolicy::HIDE,
        ]);
    }

    #[Given('/^the store has "([^"]+)" shipping method for (UPS|FedEx) service "([^"]+)" that costs "\$(\d+(?:\.\d{1,2})?)" when (?:UPS|FedEx) fails$/')]
    public function theStoreHasShippingMethodForServiceWithAFlatAmount(string $shippingMethodName, string $carrierName, string $serviceCode, string $flatAmount): void
    {
        $channel = $this->sharedStorage->get('channel');
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $this->createShippingMethod($shippingMethodName, $carrierName, [
            'service' => $serviceCode,
            'failure_policy' => FailurePolicy::FLAT,
            'flat_amount' => [(string) $channel->getCode() => self::minorUnits($flatAmount)],
        ]);
    }

    #[Given('/^(UPS|FedEx) rates service "([^"]+)" at "\$(\d+(?:\.\d{1,2})?)"$/')]
    public function theCarrierRatesServiceAt(string $carrierName, string $serviceCode, string $amount): void
    {
        $this->fakeCarrierState->rateService(self::carrierCode($carrierName), $serviceCode, self::minorUnits($amount), 'USD');
    }

    #[Given('/^(UPS|FedEx) (does not answer in time|answers with a server error|answers with something unreadable|rejects the store\'s credentials)$/')]
    public function theCarrierFails(string $carrierName, string $failure): void
    {
        $this->fakeCarrierState->fail(self::carrierCode($carrierName), match ($failure) {
            'does not answer in time' => FakeCarrierState::FAILURE_TIMEOUT,
            'answers with a server error' => FakeCarrierState::FAILURE_SERVER_ERROR,
            'answers with something unreadable' => FakeCarrierState::FAILURE_UNREADABLE,
            default => FakeCarrierState::FAILURE_CREDENTIALS,
        });
    }

    /**
     * The rates the carriers gave are kept for a while; without them, the next rate has to come from the carrier.
     */
    #[Given('the store no longer keeps any rate the carriers gave')]
    public function theStoreNoLongerKeepsAnyRate(): void
    {
        $this->rateCache->clear();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createShippingMethod(string $shippingMethodName, string $carrierName, array $configuration): void
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
                'configuration' => $configuration,
            ],
        ]);

        $this->shippingMethodRepository->add($shippingMethod);
        $this->sharedStorage->set('shipping_method', $shippingMethod);
    }

    private static function minorUnits(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function carrierCode(string $carrierName): string
    {
        return 'FedEx' === $carrierName ? CarrierCredentialsInterface::CARRIER_FEDEX : CarrierCredentialsInterface::CARRIER_UPS;
    }
}
