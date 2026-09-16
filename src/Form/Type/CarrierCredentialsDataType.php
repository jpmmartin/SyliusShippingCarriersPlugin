<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The values stored in CarrierCredentials::$credentials (D-13).
 */
final class CarrierCredentialsDataType extends AbstractType
{
    public const CLIENT_ID = 'client_id';

    public const CLIENT_SECRET = 'client_secret';

    public const ACCOUNT_NUMBER = 'account_number';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::CLIENT_ID, TextType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.client_id',
            ])
            // Always rendered empty (D-14). Not required in HTML: on edit, empty keeps the stored one.
            ->add(self::CLIENT_SECRET, PasswordType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.client_secret',
                'help' => 'jpmmartin_carrier.form.credentials.client_secret_help',
                'required' => false,
                'always_empty' => true,
            ])
            ->add(self::ACCOUNT_NUMBER, TextType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.account_number',
                'required' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_credentials_data';
    }
}
