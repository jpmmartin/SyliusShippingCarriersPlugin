<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection;

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

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.xml');

        $this->registerResources('jpmmartin_carrier', $config['driver'], $config['resources'], $container);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);
        $this->prependDoctrineMapping($container);
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
