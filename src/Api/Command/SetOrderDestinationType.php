<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Api\Command;

use Sylius\Bundle\ApiBundle\Attribute\OrderTokenValueAware;

/**
 * Says whether the order goes to a home or to a business, which changes what the carriers charge.
 */
#[OrderTokenValueAware]
class SetOrderDestinationType
{
    public function __construct(
        public readonly string $orderTokenValue = '',
        public readonly string $type = '',
    ) {
    }
}
