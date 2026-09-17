<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\ShippingOrigin;

use Sylius\Behat\Page\Admin\Crud\CreatePage as BaseCreatePage;

final class CreatePage extends BaseCreatePage
{
    public function specifyAddress(string $street, string $city, string $postcode, string $countryName): void
    {
        $this->getElement('street')->setValue($street);
        $this->getElement('city')->setValue($city);
        $this->getElement('postcode')->setValue($postcode);
        $this->getElement('country_code')->selectOption($countryName);
    }

    public function chooseChannel(string $channelName): void
    {
        $this->getElement('channel')->selectOption($channelName);
    }

    public function chooseUnits(string $weightUnit, string $dimensionUnit): void
    {
        $this->getElement('weight_unit')->selectOption($weightUnit);
        $this->getElement('dimension_unit')->selectOption($dimensionUnit);
    }

    public function chooseDefaultDestinationType(string $destinationType): void
    {
        $this->getElement('default_destination_type')->selectOption($destinationType);
    }

    public function restrictToBox(string $boxName): void
    {
        $this->getElement('boxes')->selectOption($boxName, true);
    }

    /**
     * @return array<string, string>
     */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'boxes' => '#jpmmartin_carrier_shipping_origin_boxes',
            'channel' => '#jpmmartin_carrier_shipping_origin_channel',
            'city' => '#jpmmartin_carrier_shipping_origin_city',
            'country_code' => '#jpmmartin_carrier_shipping_origin_countryCode',
            'default_destination_type' => '#jpmmartin_carrier_shipping_origin_defaultDestinationType',
            'dimension_unit' => '#jpmmartin_carrier_shipping_origin_dimensionUnit',
            'postcode' => '#jpmmartin_carrier_shipping_origin_postcode',
            'street' => '#jpmmartin_carrier_shipping_origin_street',
            'weight_unit' => '#jpmmartin_carrier_shipping_origin_weightUnit',
        ]);
    }
}
