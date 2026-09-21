<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Issues the labels of several shipments in one go.
 */
interface BatchIssuerInterface
{
    /**
     * @param iterable<ShipmentInterface> $shipments
     * @param string $issuedBy Who asked for it, as it is shown afterwards
     *
     * @return list<BatchResult> One per shipment, in the order they were given
     */
    public function issue(iterable $shipments, string $issuedBy): array;
}
