<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\ShippingOrigin;

use Sylius\Behat\Page\Admin\Crud\UpdatePage as BaseUpdatePage;

final class UpdatePage extends BaseUpdatePage
{
    public function fillSetting(string $setting, string $value): void
    {
        $this->getElement($setting)->setValue($value);
    }

    public function chooseLabelFormat(string $carrier, string $format): void
    {
        $this->getElement($carrier . '_label_format')->selectOption($format);
    }

    /**
     * What the form says under a field, such as what applies when it is left empty.
     */
    public function getHelp(string $setting): string
    {
        $field = $this->getElement($setting);

        return $this->getDocument()->find('css', '#' . $field->getAttribute('id') . '_help')?->getText() ?? '';
    }

    /**
     * @return array<string, string>
     */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'carrier_timeout' => '#jpmmartin_carrier_shipping_origin_carrierTimeout',
            'documents_retention' => '#jpmmartin_carrier_shipping_origin_documentsRetention',
            'fedex_label_format' => '#jpmmartin_carrier_shipping_origin_fedexLabelFormat',
            'fedex_services' => '#jpmmartin_carrier_shipping_origin_fedexServices',
            'rate_lifetime' => '#jpmmartin_carrier_shipping_origin_rateLifetime',
            'rate_retention' => '#jpmmartin_carrier_shipping_origin_rateRetention',
            'tracking_lifetime' => '#jpmmartin_carrier_shipping_origin_trackingLifetime',
            'ups_label_format' => '#jpmmartin_carrier_shipping_origin_upsLabelFormat',
            'ups_services' => '#jpmmartin_carrier_shipping_origin_upsServices',
        ]);
    }
}
