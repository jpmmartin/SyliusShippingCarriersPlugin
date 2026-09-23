<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * An order is not completed with a carrier's shipping method that has nothing to charge, such as one that hides
 * while its carrier is down. Checked on the order in the shop and on the completion command in the API.
 *
 * @internal
 */
final class CarrierShippingMethodAvailable extends Constraint
{
    public string $message = 'jpmmartin_carrier.order.shipping_method_unavailable';

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function validatedBy(): string
    {
        return 'jpmmartin_carrier_carrier_shipping_method_available';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
