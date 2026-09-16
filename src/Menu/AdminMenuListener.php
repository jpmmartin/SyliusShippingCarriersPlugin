<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
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
}
