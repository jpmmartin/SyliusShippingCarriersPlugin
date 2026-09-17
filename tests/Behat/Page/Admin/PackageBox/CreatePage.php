<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Page\Admin\PackageBox;

use Sylius\Behat\Page\Admin\Crud\CreatePage as BaseCreatePage;

final class CreatePage extends BaseCreatePage
{
    public function nameIt(string $name): void
    {
        $this->getElement('name')->setValue($name);
    }

    public function specifyInnerMeasures(string $length, string $width, string $height): void
    {
        $this->getElement('inner_length')->setValue($length);
        $this->getElement('inner_width')->setValue($width);
        $this->getElement('inner_height')->setValue($height);
    }

    public function specifyOuterMeasures(string $length, string $width, string $height): void
    {
        $this->getElement('outer_length')->setValue($length);
        $this->getElement('outer_width')->setValue($width);
        $this->getElement('outer_height')->setValue($height);
    }

    public function specifyWeights(string $emptyWeight, string $maxWeight): void
    {
        $this->getElement('empty_weight')->setValue($emptyWeight);
        $this->getElement('max_weight')->setValue($maxWeight);
    }

    /**
     * @return array<string, string>
     */
    protected function getDefinedElements(): array
    {
        return array_merge(parent::getDefinedElements(), [
            'empty_weight' => '#jpmmartin_carrier_package_box_emptyWeight',
            'inner_height' => '#jpmmartin_carrier_package_box_innerHeight',
            'inner_length' => '#jpmmartin_carrier_package_box_innerLength',
            'inner_width' => '#jpmmartin_carrier_package_box_innerWidth',
            'max_weight' => '#jpmmartin_carrier_package_box_maxWeight',
            'name' => '#jpmmartin_carrier_package_box_name',
            'outer_height' => '#jpmmartin_carrier_package_box_outerHeight',
            'outer_length' => '#jpmmartin_carrier_package_box_outerLength',
            'outer_width' => '#jpmmartin_carrier_package_box_outerWidth',
        ]);
    }
}
