<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Grid;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use Sylius\Bundle\GridBundle\Builder\Action\CreateAction;
use Sylius\Bundle\GridBundle\Builder\Action\DeleteAction;
use Sylius\Bundle\GridBundle\Builder\Action\UpdateAction;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\BulkActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\ItemActionGroup;
use Sylius\Bundle\GridBundle\Builder\ActionGroup\MainActionGroup;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Component\Grid\Attribute\AsGrid;
use Sylius\Component\Grid\Builder\GridBuilderInterface;

/** @internal */
#[AsGrid(name: self::NAME, resourceClass: CarrierPackageBox::class)]
final class PackageBoxGrid
{
    public const NAME = 'jpmmartin_carrier_admin_package_box';

    public function __invoke(GridBuilderInterface $gridBuilder): void
    {
        $gridBuilder
            ->addField(StringField::create('name')->setLabel('jpmmartin_carrier.form.package_box.name'))
            ->addField(StringField::create('outerLength')->setLabel('jpmmartin_carrier.ui.box_grid.outer_length'))
            ->addField(StringField::create('outerWidth')->setLabel('jpmmartin_carrier.ui.box_grid.outer_width'))
            ->addField(StringField::create('outerHeight')->setLabel('jpmmartin_carrier.ui.box_grid.outer_height'))
            ->addField(StringField::create('maxWeight')->setLabel('jpmmartin_carrier.ui.box_grid.max_weight'))
            ->addActionGroup(MainActionGroup::create(CreateAction::create()))
            ->addActionGroup(ItemActionGroup::create(UpdateAction::create(), DeleteAction::create()))
            ->addActionGroup(BulkActionGroup::create(DeleteAction::create()))
        ;
    }
}
