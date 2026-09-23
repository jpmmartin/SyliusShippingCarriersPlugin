<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Sylius finds the configuration form of a calculator by its class, so each carrier needs its own.
 *
 * @internal
 */
final class FedexRateConfigurationType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('carrier', CarrierCredentialsInterface::CARRIER_FEDEX);
    }

    public function getParent(): string
    {
        return CarrierRateConfigurationType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_fedex_rate_configuration';
    }
}
