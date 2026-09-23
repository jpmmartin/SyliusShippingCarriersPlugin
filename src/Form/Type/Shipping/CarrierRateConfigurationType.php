<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\FailurePolicy;
use Sylius\Bundle\CoreBundle\Form\Type\ChannelCollectionType;
use Sylius\Bundle\MoneyBundle\Form\Type\MoneyType;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Webmozart\Assert\Assert;

/**
 * The configuration of a shipping method rated by a carrier, shown in the admin's shipping method form once its
 * calculator is chosen. The flat amount is set by channel, in the channel's base currency, like Sylius's own flat
 * rate.
 *
 * @internal
 */
final class CarrierRateConfigurationType extends AbstractType
{
    public function __construct(
        private readonly CarrierServices $services,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $carrier */
        $carrier = $options['carrier'];

        $builder
            ->add(CarrierRateCalculator::SERVICE, ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_calculator.service',
                'placeholder' => 'jpmmartin_carrier.form.shipping_calculator.choose_service',
                'choices' => $this->services->codes($carrier),
                'choice_label' => fn (string $code): string => $this->services->name($carrier, $code) ?? $code,
                'choice_translation_domain' => false,
            ])
            ->add(CarrierRateCalculator::FAILURE_POLICY, ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_calculator.failure_policy',
                'expanded' => true,
                'choices' => [
                    'jpmmartin_carrier.form.shipping_calculator.failure_policies.hide' => FailurePolicy::HIDE,
                    'jpmmartin_carrier.form.shipping_calculator.failure_policies.flat' => FailurePolicy::FLAT,
                ],
                'empty_data' => FailurePolicy::HIDE,
            ])
            ->add(CarrierRateCalculator::FLAT_AMOUNT, ChannelCollectionType::class, [
                'label' => 'jpmmartin_carrier.form.shipping_calculator.flat_amount',
                'help' => 'jpmmartin_carrier.form.shipping_calculator.flat_amount_help',
                'required' => false,
                'entry_type' => MoneyType::class,
                'entry_options' => static function (ChannelInterface $channel): array {
                    $currencyCode = $channel->getBaseCurrency()?->getCode();
                    Assert::string($currencyCode, sprintf('The channel "%s" has no base currency.', (string) $channel->getCode()));

                    return [
                        'label' => $channel->getName(),
                        'currency' => $currencyCode,
                        'required' => false,
                    ];
                },
            ])
            // A new shipping method starts hidden when its carrier fails.
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $data = $event->getData();
                $data = is_array($data) ? $data : [];
                $data[CarrierRateCalculator::FAILURE_POLICY] ??= FailurePolicy::HIDE;

                $event->setData($data);
            })
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('data_class', null)
            ->setRequired('carrier')
            ->setAllowedTypes('carrier', 'string')
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_rate_configuration';
    }
}
