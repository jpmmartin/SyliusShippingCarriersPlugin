<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class CarrierCredentialsType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('carrier', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.carrier',
                'placeholder' => false,
                'choices' => [
                    'jpmmartin_carrier.form.credentials.carriers.ups' => CarrierCredentialsInterface::CARRIER_UPS,
                    'jpmmartin_carrier.form.credentials.carriers.fedex' => CarrierCredentialsInterface::CARRIER_FEDEX,
                ],
            ])
            // Nothing preselected: pointing at production must be an explicit choice.
            ->add('environment', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.environment',
                'placeholder' => 'jpmmartin_carrier.form.credentials.choose_environment',
                'choices' => [
                    'jpmmartin_carrier.form.credentials.environments.sandbox' => CarrierCredentialsInterface::ENVIRONMENT_SANDBOX,
                    'jpmmartin_carrier.form.credentials.environments.production' => CarrierCredentialsInterface::ENVIRONMENT_PRODUCTION,
                ],
            ])
            // Nothing preselected either: it changes the price.
            ->add('pickupType', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.pickup_type',
                'placeholder' => 'jpmmartin_carrier.form.credentials.choose_pickup_type',
                'choices' => [
                    'jpmmartin_carrier.form.credentials.pickup_types.scheduled' => CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED,
                    'jpmmartin_carrier.form.credentials.pickup_types.drop_off' => CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF,
                    'jpmmartin_carrier.form.credentials.pickup_types.on_demand' => CarrierCredentialsInterface::PICKUP_TYPE_ON_DEMAND,
                ],
            ])
            // Preselected on the recipient, unlike the two above: it changes no price, and the checkout never
            // charged the buyer any duties.
            ->add('dutiesPayer', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.duties_payer',
                'help' => 'jpmmartin_carrier.form.credentials.duties_payer_help',
                'placeholder' => false,
                'choices' => [
                    'jpmmartin_carrier.form.credentials.duties_payers.recipient' => CarrierCredentialsInterface::DUTIES_PAYER_RECIPIENT,
                    'jpmmartin_carrier.form.credentials.duties_payers.shipper' => CarrierCredentialsInterface::DUTIES_PAYER_SHIPPER,
                ],
            ])
            ->add('credentials', CarrierCredentialsDataType::class, [
                'label' => false,
            ])
            ->addEventListener(FormEvents::PRE_SUBMIT, $this->keepTheStoredSecretWhenLeftEmpty(...))
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_credentials';
    }

    /**
     * A field left empty on an edit means "unchanged", not "remove it", in two cases.
     *
     * The secret is never drawn, so leaving it empty keeps the stored one.
     *
     * And a value the store cannot read — the key changed, is missing, or the database came from another
     * installation — is drawn empty, so leaving it empty cannot mean the administrator wanted it gone: it is sent
     * back as it is stored, and the validation refuses it as unreadable. Otherwise an optional value such as the
     * UPS account number would be lost without a word, and with it the negotiated rates and the labels.
     */
    private function keepTheStoredSecretWhenLeftEmpty(FormEvent $event): void
    {
        $stored = $event->getForm()->getData();
        $submitted = $event->getData();

        if (!$stored instanceof CarrierCredentialsInterface || !\is_array($submitted) || !\is_array($submitted['credentials'] ?? null)) {
            return;
        }

        foreach ($stored->getCredentials() as $name => $storedValue) {
            if ('' !== ($submitted['credentials'][$name] ?? '')) {
                continue;
            }

            $unreadable = str_ends_with($storedValue, EncrypterInterface::ENCRYPTION_SUFFIX);
            if ($unreadable || CarrierCredentialsDataType::CLIENT_SECRET === $name) {
                $submitted['credentials'][$name] = $storedValue;
            }
        }

        $event->setData($submitted);
    }
}
