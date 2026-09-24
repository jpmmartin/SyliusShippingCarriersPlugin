<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsData;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestination;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierPackageBoxRepository;
use JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierShipmentExportRepository;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/** @internal */
final class Configuration implements ConfigurationInterface
{
    public const DEFAULT_DOCUMENTS_DIR = '%kernel.project_dir%/var/jpmmartin_carrier/documents';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('jpm_martin_sylius_shipping_carriers');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
                ->floatNode('carrier_timeout')
                    ->info('Seconds a request to a carrier may take before it counts as a failure of the carrier.')
                    ->defaultValue(10.0)
                    ->min(CarrierSettings::MIN_CARRIER_TIMEOUT)
                ->end()
                ->integerNode('rate_lifetime')
                    ->info('Seconds a stored rate is quoted for before the carrier is asked again.')
                    ->defaultValue(900)
                    ->min(CarrierSettings::MIN_SECONDS)
                ->end()
                ->integerNode('rate_retention')
                    ->info('Seconds a stored rate is kept as the last known rate, charged when the carrier fails. Not less than rate_lifetime.')
                    ->defaultValue(86400)
                    ->min(CarrierSettings::MIN_SECONDS)
                ->end()
                ->scalarNode('documents_dir')
                    ->info('Where the labels and the customs documents the plugin issues are kept. Outside the published directory on purpose.')
                    ->defaultValue(self::DEFAULT_DOCUMENTS_DIR)
                    ->cannotBeEmpty()
                ->end()
                ->integerNode('documents_retention')
                    ->info('Seconds a label or a customs document is kept before the purge deletes the file. Defaults to 180 days, the longest a shipment can be cancelled for.')
                    ->defaultValue(180 * 24 * 60 * 60)
                    ->min(CarrierSettings::MIN_SECONDS)
                ->end()
                ->integerNode('temporary_documents_retention')
                    ->info('Seconds a document waiting to be named by a row is left alone before the purge collects it. Defaults to a day, far longer than issuing takes.')
                    ->defaultValue(24 * 60 * 60)
                    ->min(CarrierSettings::MIN_SECONDS)
                ->end()
                ->integerNode('tracking_lifetime')
                    ->info('Seconds the status of a shipment is kept before the carrier is asked again.')
                    ->defaultValue(300)
                    ->min(CarrierSettings::MIN_SECONDS)
                ->end()
                ->arrayNode('label_formats')
                    ->info('What to ask each carrier to print its labels as. The two carriers share no format: UPS does not issue PDF.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // An environment variable is checked against an empty string when the container is
                        // compiled, so the empty string passes here and a written one is refused below, where the
                        // variable is still a placeholder. What a variable holds is checked when a label is issued.
                        ->scalarNode('ups')
                            ->defaultValue(LabelFormats::DEFAULTS[CarrierCredentialsInterface::CARRIER_UPS])
                            ->validate()
                                ->ifTrue(static fn (mixed $format): bool => self::isUnsupportedLabelFormat(CarrierCredentialsInterface::CARRIER_UPS, $format))
                                ->thenInvalid('UPS does not print labels as %s. It offers GIF, ZPL, EPL and SPL.')
                            ->end()
                        ->end()
                        ->scalarNode('fedex')
                            ->defaultValue(LabelFormats::DEFAULTS[CarrierCredentialsInterface::CARRIER_FEDEX])
                            ->validate()
                                ->ifTrue(static fn (mixed $format): bool => self::isUnsupportedLabelFormat(CarrierCredentialsInterface::CARRIER_FEDEX, $format))
                                ->thenInvalid('FedEx is not known to print labels as %s. It offers PDF and ZPLII.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('services')
                    ->info('The services an administrator can choose for a shipping method, as the carrier\'s service code and the name shown for it. Entries are added to the ones the plugin ships with, or rename them.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('ups')
                            ->useAttributeAsKey('code')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                        ->arrayNode('fedex')
                            ->useAttributeAsKey('code')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            // Only when both are written. A setting given by an environment variable is a placeholder here, and a
            // placeholder compared with a number is a comparison of two strings, which fails or passes by accident.
            // Values that come from variables are compared when they are used.
            ->validate()
                ->ifTrue(static fn (array $config): bool => is_int($config['rate_retention']) && is_int($config['rate_lifetime']) && $config['rate_retention'] < $config['rate_lifetime'])
                ->thenInvalid('rate_retention cannot be less than rate_lifetime: a rate would be gone before it expired.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => '' === ($config['label_formats'][CarrierCredentialsInterface::CARRIER_UPS] ?? null) || '' === ($config['label_formats'][CarrierCredentialsInterface::CARRIER_FEDEX] ?? null))
                ->thenInvalid('A label format cannot be empty. UPS offers GIF, ZPL, EPL and SPL; FedEx offers PDF and ZPLII.')
            ->end()
        ;

        $this->addResourcesSection($rootNode);

        return $treeBuilder;
    }

    private static function isUnsupportedLabelFormat(string $carrier, mixed $format): bool
    {
        return '' !== $format && !in_array($format, LabelFormats::SUPPORTED[$carrier], true);
    }

    private function addResourcesSection(ArrayNodeDefinition $node): void
    {
        $node
            ->children()
                ->arrayNode('resources')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('shipping_origin')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierShippingOrigin::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierShippingOriginInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('credentials')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierCredentials::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierCredentialsInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('package_box')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierPackageBox::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierPackageBoxInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(CarrierPackageBoxRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('shipment_export')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierShipmentExport::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierShipmentExportInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('repository')->defaultValue(CarrierShipmentExportRepository::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('shipment_label')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierShipmentLabel::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierShipmentLabelInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('customs_data')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierCustomsData::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierCustomsDataInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('order_destination')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierOrderDestination::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierOrderDestinationInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('shipment_packaging')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierShipmentPackaging::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierShipmentPackagingInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('shipment_package')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->variableNode('options')->end()
                                ->arrayNode('classes')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('model')->defaultValue(CarrierShipmentPackage::class)->cannotBeEmpty()->end()
                                        ->scalarNode('interface')->defaultValue(CarrierShipmentPackageInterface::class)->cannotBeEmpty()->end()
                                        ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                        ->scalarNode('factory')->defaultValue(Factory::class)->cannotBeEmpty()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
