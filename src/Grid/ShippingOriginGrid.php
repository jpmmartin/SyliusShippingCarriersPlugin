<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Grid;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
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
 * A PHP grid, not YAML: grid-bundle 1.16 deprecates YAML grids and AbstractGrid in favour of
 * #[AsGrid] (vendor/sylius/grid-bundle/UPGRADE.md).
 *
 * @internal
 */
#[AsGrid(name: self::NAME, resourceClass: CarrierShippingOrigin::class)]
final class ShippingOriginGrid
{
    public const NAME = 'jpmmartin_carrier_admin_shipping_origin';

    public function __invoke(GridBuilderInterface $gridBuilder): void
    {
        $gridBuilder
            ->addField(StringField::create('channel')->setPath('channel.name')->setLabel('sylius.ui.channel'))
            ->addField(StringField::create('city')->setLabel('sylius.form.address.city'))
            ->addField(StringField::create('countryCode')->setLabel('sylius.form.address.country'))
            ->addField(StringField::create('weightUnit')->setLabel('jpmmartin_carrier.form.shipping_origin.weight_unit'))
            ->addField(StringField::create('dimensionUnit')->setLabel('jpmmartin_carrier.form.shipping_origin.dimension_unit'))
            ->addActionGroup(MainActionGroup::create(CreateAction::create()))
            ->addActionGroup(ItemActionGroup::create(UpdateAction::create(), DeleteAction::create()))
            ->addActionGroup(BulkActionGroup::create(DeleteAction::create()))
        ;
    }
}
