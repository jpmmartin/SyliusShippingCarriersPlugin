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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

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
            // Who the parcel is from: printed on the label, and where a returned parcel goes back to.
            ->add('companyName', TextType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.company_name',
            ])
            ->add('contactName', TextType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.contact_name',
            ])
            ->add('phone', TextType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.phone',
                'help' => 'jpmmartin_carrier.form.shipping_origin.phone_help',
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
            // Nothing preselected: it changes the price when the buyer has not chosen a type.
            ->add('defaultDestinationType', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.default_destination_type',
                'help' => 'jpmmartin_carrier.form.shipping_origin.default_destination_type_help',
                'placeholder' => 'jpmmartin_carrier.form.shipping_origin.choose_default_destination_type',
                'choices' => [
                    'jpmmartin_carrier.form.destination_type.residential' => DestinationType::RESIDENTIAL,
                    'jpmmartin_carrier.form.destination_type.commercial' => DestinationType::COMMERCIAL,
                ],
            ])
            // Optional restriction to part of the catalog; none selected means every box.
            ->add('boxes', EntityType::class, [
                'class' => $this->boxClass,
                'label' => 'jpmmartin_carrier.form.shipping_origin.boxes',
                'help' => 'jpmmartin_carrier.form.shipping_origin.boxes_help',
                'choice_label' => 'name',
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, self::keepTheDefaultMaximumInTheChosenUnit(...))
        ;
    }

    /**
     * The form is drawn with the maximum package weight of the unit the origin had — 150, in pounds, for a new
     * one — and sends it back as it was drawn. Changing the unit without touching the maximum would otherwise
     * store 150 in kilograms, more than twice what either carrier accepts. So a maximum sent back unchanged
     * from the default of the old unit becomes the default of the new one. One that somebody typed is kept.
     */
    private static function keepTheDefaultMaximumInTheChosenUnit(FormEvent $event): void
    {
        $origin = $event->getForm()->getData();
        $submitted = $event->getData();
        if (!$origin instanceof CarrierShippingOriginInterface || !is_array($submitted)) {
            return;
        }

        $unit = $submitted['weightUnit'] ?? null;
        if (!is_string($unit) || $unit === $origin->getWeightUnit()) {
            return;
        }

        $shown = $origin->getMaxPackageWeight();
        $sent = $submitted['maxPackageWeight'] ?? null;
        $defaults = CarrierShippingOriginInterface::DEFAULT_MAX_PACKAGE_WEIGHTS;
        if (
            $shown !== ($defaults[$origin->getWeightUnit()] ?? null) ||
            !is_numeric($sent) || (float) $sent !== $shown ||
            !isset($defaults[$unit])
        ) {
            return;
        }

        $submitted['maxPackageWeight'] = (string) $defaults[$unit];
        $event->setData($submitted);
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_shipping_origin';
    }
}
