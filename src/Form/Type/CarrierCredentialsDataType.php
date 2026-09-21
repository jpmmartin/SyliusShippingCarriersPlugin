<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Type;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * The values stored in CarrierCredentials::$credentials.
 */
final class CarrierCredentialsDataType extends AbstractType
{
    public const CLIENT_ID = CarrierCredentialsInterface::CLIENT_ID;

    public const CLIENT_SECRET = CarrierCredentialsInterface::CLIENT_SECRET;

    public const ACCOUNT_NUMBER = CarrierCredentialsInterface::ACCOUNT_NUMBER;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::CLIENT_ID, TextType::class, [
                'label' => 'jpmmartin_carrier.form.credentials.client_id',
            ])
            // Always rendered empty. Not required in HTML: on edit, empty keeps the stored one.
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
            ->addEventListener(FormEvents::PRE_SET_DATA, self::drawUnreadableValuesEmpty(...))
        ;
    }

    /**
     * A value the store could not decrypt — the key changed, is missing, or the database came from another
     * installation — is still its ciphertext. Drawn as it is, the administrator sees 178 characters that mean
     * nothing; kept, the carrier stays unusable after a save that says it worked. So it is drawn empty, with a
     * word on why, and has to be typed again.
     */
    private static function drawUnreadableValuesEmpty(FormEvent $event): void
    {
        $values = $event->getData();
        if (!\is_array($values)) {
            return;
        }

        $form = $event->getForm();
        foreach ($values as $name => $value) {
            if (!\is_string($value) || !str_ends_with($value, EncrypterInterface::ENCRYPTION_SUFFIX) || !$form->has((string) $name)) {
                continue;
            }

            $values[$name] = '';
            $field = $form->get((string) $name);
            $form->add((string) $name, $field->getConfig()->getType()->getInnerType()::class, array_merge($field->getConfig()->getOptions(), [
                'help' => 'jpmmartin_carrier.form.credentials.unreadable',
            ]));
        }

        $event->setData($values);
    }

    public function getBlockPrefix(): string
    {
        return 'jpmmartin_carrier_credentials_data';
    }
}
