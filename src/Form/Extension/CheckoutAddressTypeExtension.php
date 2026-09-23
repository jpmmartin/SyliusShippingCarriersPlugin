<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Extension;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface;
use Sylius\Bundle\ShopBundle\Form\Type\Checkout\AddressType;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Lets the buyer say, in the address step of the shop checkout, whether the order goes to a home or a
 * business. The field belongs to the whole form, not to the shipping address: a channel that does not
 * ask for a separate shipping address hides that block and copies the billing address into it.
 *
 * @internal
 */
final class CheckoutAddressTypeExtension extends AbstractTypeExtension
{
    private const FIELD = 'destinationType';

    /**
     * @param RepositoryInterface<CarrierOrderDestinationInterface> $destinationRepository
     * @param FactoryInterface<CarrierOrderDestinationInterface> $destinationFactory
     */
    public function __construct(
        private readonly DestinationTypeResolverInterface $destinationTypeResolver,
        private readonly RepositoryInterface $destinationRepository,
        private readonly FactoryInterface $destinationFactory,
        private readonly ObjectManager $manager,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
                $order = $event->getData();
                if (!$order instanceof OrderInterface) {
                    return;
                }

                // No type at all means no origin for the channel, where the plugin does not quote.
                $type = $this->destinationTypeResolver->resolve($order);
                if (null === $type) {
                    return;
                }

                $event->getForm()->add(self::FIELD, ChoiceType::class, [
                    'mapped' => false,
                    'expanded' => true,
                    'data' => $type,
                    'label' => 'jpmmartin_carrier.form.checkout.destination_type',
                    'choices' => [
                        'jpmmartin_carrier.form.destination_type.residential' => DestinationType::RESIDENTIAL,
                        'jpmmartin_carrier.form.destination_type.commercial' => DestinationType::COMMERCIAL,
                    ],
                    'constraints' => [
                        new NotBlank(message: 'jpmmartin_carrier.checkout.destination_type.not_blank', groups: ['sylius']),
                        new Choice(choices: [DestinationType::RESIDENTIAL, DestinationType::COMMERCIAL], groups: ['sylius']),
                    ],
                ]);
            })
            // Persisted, not flushed: the checkout saves it with the order in the same flush.
            ->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                $order = $event->getData();
                if (!$order instanceof OrderInterface || !$form->has(self::FIELD) || !$form->isValid()) {
                    return;
                }

                $type = $form->get(self::FIELD)->getData();
                if (!\is_string($type)) {
                    return;
                }

                $destination = $this->destinationRepository->findOneBy(['order' => $order]) ?? $this->destinationFactory->createNew();
                $destination->setOrder($order);
                $destination->setType($type);

                $this->manager->persist($destination);
            })
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        return [AddressType::class];
    }
}
