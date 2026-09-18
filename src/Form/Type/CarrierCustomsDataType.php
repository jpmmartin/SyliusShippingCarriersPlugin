<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class CarrierCustomsDataType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('hsCode', TextType::class, [
                'label' => 'jpmmartin_carrier.form.customs_data.hs_code',
                'help' => 'jpmmartin_carrier.form.customs_data.hs_code_help',
                'required' => false,
            ])
            // Symfony's country list, not the store's: goods are made in countries a store may never ship to.
            ->add('countryOfOrigin', CountryType::class, [
                'label' => 'jpmmartin_carrier.form.customs_data.country_of_origin',
                'help' => 'jpmmartin_carrier.form.customs_data.country_of_origin_help',
                'placeholder' => 'jpmmartin_carrier.form.customs_data.choose_country_of_origin',
                'required' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_customs_data';
    }
}
