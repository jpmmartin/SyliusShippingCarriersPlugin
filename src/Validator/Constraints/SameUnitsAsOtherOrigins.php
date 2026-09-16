<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Every origin declares the same units (D-16).
 */
final class SameUnitsAsOtherOrigins extends Constraint
{
    public string $weightUnitMessage = 'jpmmartin_carrier.shipping_origin.weight_unit.same_as_other_origins';

    public string $dimensionUnitMessage = 'jpmmartin_carrier.shipping_origin.dimension_unit.same_as_other_origins';

    public function validatedBy(): string
    {
        return 'jpmmartin_carrier_same_units_as_other_origins';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
