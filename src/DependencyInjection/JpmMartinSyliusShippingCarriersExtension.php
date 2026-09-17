<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class JpmMartinSyliusShippingCarriersExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    /** @psalm-suppress UnusedVariable */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('jpmmartin_carrier.carrier_timeout', $config['carrier_timeout']);
        $container->setParameter('jpmmartin_carrier.rate_lifetime', $config['rate_lifetime']);
        $container->setParameter('jpmmartin_carrier.rate_retention', $config['rate_retention']);
        $container->setParameter('jpmmartin_carrier.tracking_lifetime', $config['tracking_lifetime']);
        $container->setParameter('jpmmartin_carrier.services', $config['services']);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.xml');

        $this->registerResources('jpmmartin_carrier', $config['driver'], $config['resources'], $container);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);
        $this->prependDoctrineMapping($container);
        $this->prependRateCachePool($container);
        $this->prependCarrierServices($container);
        $this->prependShippingMethodValidationGroups($container);
        $this->prependApiPlatformMapping($container);
    }

    /**
     * The shop API operations the plugin adds, such as the destination type of an order.
     */
    private function prependApiPlatformMapping(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('api_platform')) {
            return;
        }

        $container->prependExtensionConfig('api_platform', [
            'mapping' => ['paths' => [realpath(__DIR__ . '/../../config/api_platform')]],
        ]);
    }

    /**
     * Declared as the first configuration, so an application's own services are added to these, key by key,
     * instead of replacing them.
     */
    private function prependCarrierServices(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig($this->getAlias(), ['services' => CarrierServices::DEFAULTS]);
    }

    /**
     * Sylius validates a shipping method with the groups set for its calculator, in the admin and in the API
     * alike. Each carrier's group checks the configuration against that carrier's services.
     */
    private function prependShippingMethodValidationGroups(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('sylius_shipping')) {
            return;
        }

        $container->prependExtensionConfig('sylius_shipping', [
            'shipping_method_calculator' => [
                'validation_groups' => [
                    'ups_rate' => ['sylius', 'jpmmartin_carrier_ups_rate'],
                    'fedex_rate' => ['sylius', 'jpmmartin_carrier_fedex_rate'],
                ],
            ],
        ]);
    }

    /**
     * Rates and the status of shipments are kept on the filesystem, which every installation has. Declared as
     * framework pools, an application points them at another adapter from its own configuration, which replaces
     * these ones.
     */
    private function prependRateCachePool(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', [
            'cache' => [
                'pools' => [
                    'jpmmartin_carrier.cache.rates' => [
                        'adapter' => 'cache.adapter.filesystem',
                    ],
                    'jpmmartin_carrier.cache.tracking' => [
                        'adapter' => 'cache.adapter.filesystem',
                    ],
                ],
            ],
        ]);
    }

    /**
     * The ResourceBundle's Doctrine driver registers the repository and the manager but not the
     * mapping, so it has to be declared here. Same shape the test application already uses for its
     * own entities.
     */
    private function prependDoctrineMapping(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'JpmMartinSyliusShippingCarriersPlugin' => [
                                'is_bundle' => false,
                                'type' => 'attribute',
                                'dir' => realpath(__DIR__ . '/../Entity'),
                                'prefix' => 'JpmMartin\SyliusShippingCarriersPlugin\Entity',
                                'alias' => 'JpmMartinSyliusShippingCarriers',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'DoctrineMigrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@JpmMartinSyliusShippingCarriersPlugin/src/Migrations';
    }

    /** @return list<string> */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
