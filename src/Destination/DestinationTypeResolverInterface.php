<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Destination;

use Sylius\Component\Core\Model\OrderInterface;

interface DestinationTypeResolverInterface
{
    /**
     * The type the buyer chose for the order or, without a choice, the default of the origin of its channel
     * (CA-48). Null when neither exists: a channel without an origin is not quoted at all.
     */
    public function resolve(OrderInterface $order): ?string;
}
