<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

use JpmMartin\SyliusShippingCarriersPlugin\Unit\StoreUnitsResolverInterface;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class CarrierPackageBoxType extends AbstractResourceType
{
    private const MEASURES = [
        'innerLength' => 'inner_length',
        'innerWidth' => 'inner_width',
        'innerHeight' => 'inner_height',
        'outerLength' => 'outer_length',
        'outerWidth' => 'outer_width',
        'outerHeight' => 'outer_height',
    ];

    private const WEIGHTS = [
        'emptyWeight' => 'empty_weight',
        'maxWeight' => 'max_weight',
    ];

    /** @param string[] $validationGroups */
    public function __construct(
        string $dataClass,
        array $validationGroups,
        private readonly StoreUnitsResolverInterface $storeUnits,
    ) {
        parent::__construct($dataClass, $validationGroups);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'jpmmartin_carrier.form.package_box.name',
        ]);

        // A box has no unit of its own: it is read in the store's units (D-16), so the labels say which.
        foreach (self::MEASURES as $field => $key) {
            $builder->add($field, NumberType::class, [
                'label' => 'jpmmartin_carrier.form.package_box.' . $key,
                'label_translation_parameters' => ['%unit%' => $this->storeUnits->getDimensionUnit()],
            ]);
        }

        foreach (self::WEIGHTS as $field => $key) {
            $builder->add($field, NumberType::class, [
                'label' => 'jpmmartin_carrier.form.package_box.' . $key,
                'label_translation_parameters' => ['%unit%' => $this->storeUnits->getWeightUnit()],
            ]);
        }
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_package_box';
    }
}
