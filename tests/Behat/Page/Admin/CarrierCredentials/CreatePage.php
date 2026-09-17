<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\CarrierCredentials;

use Sylius\Behat\Page\Admin\Crud\CreatePage as BaseCreatePage;

final class CreatePage extends BaseCreatePage
{
    public function chooseCarrier(string $carrierName): void
    {
        $this->getElement('carrier')->selectOption($carrierName);
    }

    public function chooseEnvironment(string $environment): void
    {
        $this->getElement('environment')->selectOption($environment);
    }

    public function choosePickupType(string $pickupType): void
    {
        $this->getElement('pickup_type')->selectOption($pickupType);
    }

    public function specifyCredentials(string $clientId, string $clientSecret): void
    {
        $this->getElement('client_id')->setValue($clientId);
        $this->getElement('client_secret')->setValue($clientSecret);
    }

    /**
     * @return array<string, string>
     */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'carrier' => '#jpmmartin_carrier_credentials_carrier',
            'client_id' => '#jpmmartin_carrier_credentials_credentials_client_id',
            'client_secret' => '#jpmmartin_carrier_credentials_credentials_client_secret',
            'environment' => '#jpmmartin_carrier_credentials_environment',
            'pickup_type' => '#jpmmartin_carrier_credentials_pickupType',
        ]);
    }
}
