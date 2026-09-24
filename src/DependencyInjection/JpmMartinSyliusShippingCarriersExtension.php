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

/** @internal */
final class JpmMartinSyliusShippingCarriersExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    /** The Flysystem storage the plugin keeps its labels and customs documents in. */
    public const DOCUMENT_STORAGE = 'jpmmartin_carrier.storage.documents';

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
        $container->setParameter('jpmmartin_carrier.label_formats', $config['label_formats']);
        $container->setParameter('jpmmartin_carrier.documents_dir', $config['documents_dir']);
        $container->setParameter('jpmmartin_carrier.documents_retention', $config['documents_retention']);
        $container->setParameter('jpmmartin_carrier.temporary_documents_retention', $config['temporary_documents_retention']);

        // The key of the carrier credentials, separate from Sylius's payment key. Set here rather than in the
        // plugin's config.yaml because Flex registers the bundle before anybody imports that file, and every page
        // would fail on this variable until they did. An application's own value, or the variable itself, wins.
        $container->setParameter('env(JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH)', '%kernel.project_dir%/config/encryption/jpmmartin_carrier.key');

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('services.xml');

        $this->registerResources('jpmmartin_carrier', $config['driver'], $config['resources'], $container);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependDoctrineMigrations($container);
        $this->prependDoctrineMapping($container);
        $this->prependRateCachePool($container);
        $this->prependDocumentStorage($container);
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
     * Where the labels and the customs documents the plugin issues are kept.
     *
     * Declared the way Sylius declares its own storage for images, with two differences that are the point of
     * it: it lives under var/, outside the directory the web server publishes, and it is private. An
     * application points it somewhere else — S3, another directory — by declaring a storage of the same name
     * in its own configuration, which is loaded after this one and replaces it.
     */
    private function prependDocumentStorage(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('flysystem')) {
            return;
        }

        $container->prependExtensionConfig('flysystem', [
            'storages' => [
                self::DOCUMENT_STORAGE => [
                    'adapter' => 'local',
                    'options' => ['directory' => $this->documentsDir($container)],
                    'visibility' => 'private',
                    'directory_visibility' => 'private',
                ],
            ],
        ]);
    }

    /**
     * The directory as the application wrote it, and nothing else of the configuration.
     *
     * Read here and not left as a parameter reference: `prepend` runs before `load`, so a parameter of ours does
     * not exist yet when Flysystem's extension is loaded. Read raw and not by processing the configuration: at
     * this point an environment variable is still the text `%env(...)%`, which a numeric setting refuses. A
     * variable or a parameter in the directory reaches Flysystem as written, and is resolved with its configuration.
     */
    private function documentsDir(ContainerBuilder $container): string
    {
        $directory = Configuration::DEFAULT_DOCUMENTS_DIR;

        // In the order they are merged: the last configuration that sets it decides it.
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            if (is_string($config['documents_dir'] ?? null)) {
                $directory = $config['documents_dir'];
            }
        }

        return $directory;
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

    /**
     * A namespace of the plugin's own. Sylius Standard lists the store's migrations under `DoctrineMigrations`, and
     * the store's configuration is merged over the plugin's, so under that name the plugin's would never run.
     */
    protected function getMigrationsNamespace(): string
    {
        return 'JpmMartin\SyliusShippingCarriersPlugin\Migrations';
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
