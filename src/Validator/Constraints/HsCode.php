<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * A Harmonized System code as customs writes it: six to ten digits, with the separators people type — dots,
 * spaces or hyphens — allowed and ignored.
 *
 * Six digits is the part the whole world shares; the rest is what each country adds. Anything shorter names
 * no goods, and anything longer belongs to no tariff.
 */
final class HsCode extends Constraint
{
    public string $message = 'jpmmartin_carrier.customs_data.hs_code.invalid';

    public function validatedBy(): string
    {
        return 'jpmmartin_carrier_hs_code';
    }
}
