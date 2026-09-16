<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

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
            // Nothing preselected: pointing at production must be an explicit choice (CA-7).
            ->add('environment', ChoiceType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.environment',
                'placeholder' => 'jpmmartin_carrier.form.credentials.choose_environment',
                'choices' => [
                    'jpmmartin_carrier.form.credentials.environments.sandbox' => CarrierCredentialsInterface::ENVIRONMENT_SANDBOX,
                    'jpmmartin_carrier.form.credentials.environments.production' => CarrierCredentialsInterface::ENVIRONMENT_PRODUCTION,
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
     * The secret is never rendered back (D-14), so an edit that leaves it empty means "unchanged",
     * not "remove it".
     */
    private function keepTheStoredSecretWhenLeftEmpty(FormEvent $event): void
    {
        $stored = $event->getForm()->getData();
        $submitted = $event->getData();

        if (!$stored instanceof CarrierCredentialsInterface || !\is_array($submitted) || !\is_array($submitted['credentials'] ?? null)) {
            return;
        }

        $storedSecret = $stored->getCredentials()[CarrierCredentialsDataType::CLIENT_SECRET] ?? null;
        if (null === $storedSecret || '' !== ($submitted['credentials'][CarrierCredentialsDataType::CLIENT_SECRET] ?? '')) {
            return;
        }

        $submitted['credentials'][CarrierCredentialsDataType::CLIENT_SECRET] = $storedSecret;
        $event->setData($submitted);
    }
}
