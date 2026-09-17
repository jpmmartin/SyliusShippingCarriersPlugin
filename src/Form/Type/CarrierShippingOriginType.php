<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Bundle\AddressingBundle\Form\Type\CountryCodeChoiceType;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class CarrierShippingOriginType extends AbstractResourceType
{
    /**
     * @param string[] $validationGroups
     * @param class-string $boxClass
     */
    public function __construct(
        string $dataClass,
        array $validationGroups,
        private readonly string $boxClass,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('channel', ChannelChoiceType::class, [
                'label' => 'sylius.ui.channel',
            ])
            ->add('street', TextType::class, [
                'label' => 'sylius.form.address.street',
            ])
            ->add('city', TextType::class, [
                'label' => 'sylius.form.address.city',
            ])
            ->add('postcode', TextType::class, [
                'label' => 'sylius.form.address.postcode',
            ])
            ->add('countryCode', CountryCodeChoiceType::class, [
                'label' => 'sylius.form.address.country',
            ])
            ->add('provinceCode', TextType::class, [
                'label' => 'sylius.form.address.province',
                'required' => false,
            ])
            ->add('weightUnit', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.weight_unit',
                'placeholder' => false,
                'choices' => [
                    'jpmmartin_carrier.form.shipping_origin.unit.lb' => CarrierShippingOriginInterface::WEIGHT_UNIT_LB,
                    'jpmmartin_carrier.form.shipping_origin.unit.kg' => CarrierShippingOriginInterface::WEIGHT_UNIT_KG,
                ],
            ])
            ->add('dimensionUnit', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.dimension_unit',
                'placeholder' => false,
                'choices' => [
                    'jpmmartin_carrier.form.shipping_origin.unit.in' => CarrierShippingOriginInterface::DIMENSION_UNIT_IN,
                    'jpmmartin_carrier.form.shipping_origin.unit.cm' => CarrierShippingOriginInterface::DIMENSION_UNIT_CM,
                ],
            ])
            // The setter takes a float, not null. Mapping an empty field as 0 lets the Positive
            // constraint reject it with a form error instead of a TypeError while mapping.
            ->add('maxPackageWeight', NumberType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.max_package_weight',
                'empty_data' => '0',
            ])
            // Nothing preselected: it changes the price when the buyer has not chosen a type (CA-48).
            ->add('defaultDestinationType', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.default_destination_type',
                'help' => 'jpmmartin_carrier.form.shipping_origin.default_destination_type_help',
                'placeholder' => 'jpmmartin_carrier.form.shipping_origin.choose_default_destination_type',
                'choices' => [
                    'jpmmartin_carrier.form.destination_type.residential' => DestinationType::RESIDENTIAL,
                    'jpmmartin_carrier.form.destination_type.commercial' => DestinationType::COMMERCIAL,
                ],
            ])
            // Optional restriction to part of the catalog; none selected means every box (CA-35).
            ->add('boxes', EntityType::class, [
                'class' => $this->boxClass,
                'label' => 'jpmmartin_carrier.form.shipping_origin.boxes',
                'help' => 'jpmmartin_carrier.form.shipping_origin.boxes_help',
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_shipping_origin';
    }
}
