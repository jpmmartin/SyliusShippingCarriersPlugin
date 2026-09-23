<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * The configuration of a shipping method rated by a carrier: one of the carrier's services, a known failure policy
 * and, with the flat policy, a flat amount for every channel.
 *
 * @internal
 */
final class CarrierRateConfiguration extends Constraint
{
    public string $serviceMessage = 'jpmmartin_carrier.shipping_method.service.invalid';

    public string $failurePolicyMessage = 'jpmmartin_carrier.shipping_method.failure_policy.invalid';

    public string $flatAmountMessage = 'jpmmartin_carrier.shipping_method.flat_amount.required';

    /**
     * @param string $carrier The carrier whose services the configuration may name
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        public string $carrier,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function validatedBy(): string
    {
        return 'jpmmartin_carrier_carrier_rate_configuration';
    }
}
