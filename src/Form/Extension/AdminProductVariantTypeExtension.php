<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Form\Extension;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Form\Type\CarrierCustomsDataType;
use Sylius\Bundle\AdminBundle\Form\Type\ProductVariantType;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Valid;

/**
 * Adds the customs data to the form of a product variant, so it is declared where the variant is edited and
 * not in a screen of its own.
 *
 * The data lives in a table of the plugin (see CarrierCustomsData), so it cannot simply be a mapped field of
 * the variant: the form carries it unmapped and this extension loads it and saves it alongside.
 */
final class AdminProductVariantTypeExtension extends AbstractTypeExtension
{
    public const FIELD = 'jpmmartinCarrierCustomsData';

    /**
     * @param RepositoryInterface<CarrierCustomsDataInterface> $repository
     * @param FactoryInterface<CarrierCustomsDataInterface> $factory
     */
    public function __construct(
        private readonly RepositoryInterface $repository,
        private readonly FactoryInterface $factory,
        private readonly ObjectManager $manager,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::FIELD, CarrierCustomsDataType::class, [
            'label' => 'jpmmartin_carrier.ui.customs_data',
            'mapped' => false,
            'required' => false,
            // Unmapped data is not validated with the variant unless it is asked for: without this, a code
            // customs would never recognise would be saved without a word.
            'constraints' => [new Valid()],
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $variant = $event->getData();
            if (!$variant instanceof ProductVariantInterface) {
                return;
            }

            $event->getForm()->get(self::FIELD)->setData($this->customsDataOf($variant));
        });

        // POST_SUBMIT, so nothing is written for a form that did not validate.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $variant = $event->getData();
            $form = $event->getForm();
            if (!$variant instanceof ProductVariantInterface || !$form->isValid()) {
                return;
            }

            $submitted = $form->get(self::FIELD)->getData();
            if (!$submitted instanceof CarrierCustomsDataInterface) {
                return;
            }

            // Looked up again here, and not trusted from PRE_SET_DATA: the variant form is a live component and
            // is built more than once per page, so the row that was loaded then may not be the one to write now.
            $customsData = $this->storedFor($variant) ?? $submitted;
            $customsData->setHsCode($submitted->getHsCode());
            $customsData->setCountryOfOrigin($submitted->getCountryOfOrigin());
            $customsData->setVariant($variant);

            $this->manager->persist($customsData);
        });
    }

    /**
     * @return iterable<class-string>
     */
    public static function getExtendedTypes(): iterable
    {
        return [ProductVariantType::class];
    }

    private function customsDataOf(ProductVariantInterface $variant): CarrierCustomsDataInterface
    {
        return $this->storedFor($variant) ?? $this->factory->createNew();
    }

    private function storedFor(ProductVariantInterface $variant): ?CarrierCustomsDataInterface
    {
        if (null === $variant->getId()) {
            return null;
        }

        $customsData = $this->repository->findOneBy(['variant' => $variant]);

        return $customsData instanceof CarrierCustomsDataInterface ? $customsData : null;
    }
}
