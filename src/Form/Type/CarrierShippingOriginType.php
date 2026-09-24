<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierChannelSettingsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use Sylius\Bundle\AddressingBundle\Form\Type\CountryCodeChoiceType;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

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
        private readonly CarrierSettingsProvider $settings,
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

        // A store whose own origin model knows nothing of these settings keeps the form it had.
        if (is_a($this->dataClass, CarrierChannelSettingsInterface::class, true)) {
            $this->addChannelSettings($builder);
        }
    }

    /**
     * What the channel says in place of the configuration. Every field is optional, and each one says what applies
     * when it is left empty, so nobody has to go and read the configuration to know.
     */
    private function addChannelSettings(FormBuilderInterface $builder): void
    {
        $defaults = $this->settings->defaults();

        $builder
            ->add('carrierTimeout', NumberType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.carrier_timeout',
                'help' => 'jpmmartin_carrier.form.shipping_origin.seconds_help',
                'help_translation_parameters' => ['%value%' => $defaults->carrierTimeout],
                'required' => false,
            ])
            ->add('rateLifetime', IntegerType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.rate_lifetime',
                'help' => 'jpmmartin_carrier.form.shipping_origin.seconds_help',
                'help_translation_parameters' => ['%value%' => $defaults->rateLifetime],
                'required' => false,
            ])
            ->add('rateRetention', IntegerType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.rate_retention',
                'help' => 'jpmmartin_carrier.form.shipping_origin.seconds_help',
                'help_translation_parameters' => ['%value%' => $defaults->rateRetention],
                'required' => false,
            ])
            ->add('trackingLifetime', IntegerType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.tracking_lifetime',
                'help' => 'jpmmartin_carrier.form.shipping_origin.seconds_help',
                'help_translation_parameters' => ['%value%' => $defaults->trackingLifetime],
                'required' => false,
            ])
            ->add('documentsRetention', IntegerType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_origin.documents_retention',
                'help' => 'jpmmartin_carrier.form.shipping_origin.seconds_help',
                'help_translation_parameters' => ['%value%' => $defaults->documentsRetention],
                'required' => false,
            ])
        ;

        foreach ([CarrierCredentialsInterface::CARRIER_UPS, CarrierCredentialsInterface::CARRIER_FEDEX] as $carrier) {
            $builder
                ->add($carrier . 'LabelFormat', ChoiceType::class, [
                    'label' => sprintf('jpmmartin_carrier.form.shipping_origin.%s_label_format', $carrier),
                    'help' => 'jpmmartin_carrier.form.shipping_origin.label_format_help',
                    'help_translation_parameters' => ['%value%' => $defaults->labelFormat($carrier)],
                    'placeholder' => 'jpmmartin_carrier.form.shipping_origin.as_configured',
                    'choices' => array_combine(LabelFormats::SUPPORTED[$carrier], LabelFormats::SUPPORTED[$carrier]),
                    'choice_translation_domain' => false,
                    'required' => false,
                    'getter' => static fn (CarrierChannelSettingsInterface $origin): ?string => $origin->getLabelFormat($carrier),
                    'setter' => static function (CarrierChannelSettingsInterface $origin, ?string $format) use ($carrier): void {
                        $origin->setLabelFormat($carrier, $format);
                    },
                ])
                ->add($carrier . 'Services', TextareaType::class, [
                    'label' => sprintf('jpmmartin_carrier.form.shipping_origin.%s_services', $carrier),
                    'help' => 'jpmmartin_carrier.form.shipping_origin.services_help',
                    'invalid_message' => 'jpmmartin_carrier.shipping_origin.services.invalid',
                    'required' => false,
                    'getter' => static fn (CarrierChannelSettingsInterface $origin): array => $origin->getServices($carrier),
                    'setter' => self::servicesSetter($carrier),
                ])
            ;

            $builder->get($carrier . 'Services')->addModelTransformer(new CallbackTransformer(
                self::servicesAsText(...),
                self::servicesFromText(...),
            ));
        }
    }

    /**
     * Always given a list, even an empty one: the text is turned into one before it gets here.
     *
     * @return \Closure(CarrierChannelSettingsInterface, array<string, string>): void
     */
    private static function servicesSetter(string $carrier): \Closure
    {
        /** @param array<string, string> $services */
        return static function (CarrierChannelSettingsInterface $origin, array $services) use ($carrier): void {
            $origin->setServices($carrier, $services);
        };
    }

    /**
     * @param array<string, string>|null $services
     */
    private static function servicesAsText(?array $services): string
    {
        $lines = [];
        foreach ($services ?? [] as $code => $name) {
            $lines[] = sprintf('%s = %s', $code, $name);
        }

        return implode("\n", $lines);
    }

    /**
     * One service a line, as `CODE = Name`. Blank lines are left out; any other line without both is refused.
     *
     * @return array<string, string>
     */
    private static function servicesFromText(?string $text): array
    {
        $services = [];
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $parts = explode('=', $line, 2);
            $code = trim($parts[0]);
            $name = trim($parts[1] ?? '');
            if ('' === $code || '' === $name) {
                throw new TransformationFailedException(sprintf('"%s" is not a service written as CODE = Name.', trim($line)));
            }

            $services[$code] = $name;
        }

        return $services;
    }

    /**
     * The form is drawn with the maximum package weight of the unit the origin had — 150, in pounds, for a new
     * one — and sends it back as it was drawn. Changing the unit without touching the maximum would otherwise
     * store 150 in kilograms, more than twice what either carrier accepts. So a maximum sent back exactly as it
     * was drawn, while it was the default of the old unit, becomes the default of the new one. One that
     * somebody typed is kept.
     *
     * «Exactly as it was drawn» is compared as text, in whatever digits and separators the administrator's
     * language draws it with: an Arabic admin sees ١٥٠, which is not a number to is_numeric().
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

        $defaults = CarrierShippingOriginInterface::DEFAULT_MAX_PACKAGE_WEIGHTS;
        $maximum = $event->getForm()->get('maxPackageWeight');
        if (
            $origin->getMaxPackageWeight() !== ($defaults[$origin->getWeightUnit()] ?? null) ||
            ($submitted['maxPackageWeight'] ?? null) !== $maximum->getViewData() ||
            !isset($defaults[$unit])
        ) {
            return;
        }

        $submitted['maxPackageWeight'] = self::drawn($maximum, $defaults[$unit]);
        $event->setData($submitted);
    }

    /**
     * A value as the field would draw it, so it reads back the same in the administrator's language.
     */
    private static function drawn(FormInterface $field, float $value): mixed
    {
        $drawn = $value;
        foreach ($field->getConfig()->getModelTransformers() as $transformer) {
            $drawn = $transformer->transform($drawn);
        }

        foreach ($field->getConfig()->getViewTransformers() as $transformer) {
            $drawn = $transformer->transform($drawn);
        }

        return $drawn;
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_shipping_origin';
    }
}
