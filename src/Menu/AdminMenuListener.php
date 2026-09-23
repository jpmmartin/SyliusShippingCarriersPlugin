<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/** @internal */
final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $this->addTheIncompleteCustomsData($event);

        $configuration = $event->getMenu()->getChild('configuration');
        if (null === $configuration) {
            return;
        }

        $configuration
            ->addChild('jpmmartin_carrier_shipping_origins', [
                'route' => 'jpmmartin_carrier_admin_shipping_origin_index',
                'extras' => ['routes' => [
                    ['route' => 'jpmmartin_carrier_admin_shipping_origin_create'],
                    ['route' => 'jpmmartin_carrier_admin_shipping_origin_update'],
                ]],
            ])
            ->setLabel('jpmmartin_carrier.ui.shipping_origins')
            ->setLabelAttribute('icon', 'tabler:truck')
        ;

        $configuration
            ->addChild('jpmmartin_carrier_package_boxes', [
                'route' => 'jpmmartin_carrier_admin_package_box_index',
                'extras' => ['routes' => [
                    ['route' => 'jpmmartin_carrier_admin_package_box_create'],
                    ['route' => 'jpmmartin_carrier_admin_package_box_update'],
                ]],
            ])
            ->setLabel('jpmmartin_carrier.ui.package_boxes')
            ->setLabelAttribute('icon', 'tabler:cube')
        ;

        $configuration
            ->addChild('jpmmartin_carrier_credentials', [
                'route' => 'jpmmartin_carrier_admin_credentials_index',
                'extras' => ['routes' => [
                    ['route' => 'jpmmartin_carrier_admin_credentials_create'],
                    ['route' => 'jpmmartin_carrier_admin_credentials_update'],
                ]],
            ])
            ->setLabel('jpmmartin_carrier.ui.credentials')
            ->setLabelAttribute('icon', 'tabler:lock')
        ;
    }

    /**
     * It belongs with the catalogue, not with the configuration: what it lists is products, and it is read by
     * whoever fills the catalogue in.
     */
    private function addTheIncompleteCustomsData(MenuBuilderEvent $event): void
    {
        $catalog = $event->getMenu()->getChild('catalog');
        if (null === $catalog) {
            return;
        }

        $catalog
            ->addChild('jpmmartin_carrier_incomplete_customs_data', [
                'route' => 'jpmmartin_carrier_admin_incomplete_customs_data_index',
            ])
            ->setLabel('jpmmartin_carrier.ui.incomplete_customs_data')
            ->setLabelAttribute('icon', 'tabler:alert-triangle')
        ;
    }
}
