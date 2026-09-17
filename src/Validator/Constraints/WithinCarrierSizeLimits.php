<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * A box both supported carriers accept: outer length up to 108", outer length plus girth up
 * to 165". Checked on the outer measures, the ones declared to the carrier.
 */
final class WithinCarrierSizeLimits extends Constraint
{
    public string $lengthMessage = 'jpmmartin_carrier.package_box.outer_length.too_long';

    public string $lengthPlusGirthMessage = 'jpmmartin_carrier.package_box.outer_length.length_plus_girth_too_large';

    public function validatedBy(): string
    {
        return 'jpmmartin_carrier_within_carrier_size_limits';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
