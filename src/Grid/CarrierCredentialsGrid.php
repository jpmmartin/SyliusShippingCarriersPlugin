<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Grid;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials;
use Sylius\Bundle\GridBundle\Builder\Action\CreateAction;
use Sylius\Bundle\GridBundle\Builder\Action\DeleteAction;
use Sylius\Bundle\GridBundle\Builder\Action\UpdateAction;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\ItemActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\MainActionGroup;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Component\Grid\Attribute\AsGrid;
use Sylius\Component\Grid\Builder\GridBuilderInterface;

/**
 * Carrier and environment only: the credential values never appear in the grid.
 */
#[AsGrid(name: self::NAME, resourceClass: CarrierCredentials::class)]
final class CarrierCredentialsGrid
{
    public const NAME = 'jpmmartin_carrier_admin_credentials';

    public function __invoke(GridBuilderInterface $gridBuilder): void
    {
        $gridBuilder
            ->addField(StringField::create('carrier')->setLabel('jpmmartin_carrier.form.credentials.carrier'))
            ->addField(StringField::create('environment')->setLabel('jpmmartin_carrier.form.credentials.environment'))
            ->addActionGroup(MainActionGroup::create(CreateAction::create()))
            ->addActionGroup(ItemActionGroup::create(UpdateAction::create(), DeleteAction::create()))
            ->addActionGroup(BulkActionGroup::create(DeleteAction::create()))
        ;
    }
}
