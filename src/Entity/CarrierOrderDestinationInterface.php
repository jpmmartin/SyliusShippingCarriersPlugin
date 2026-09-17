<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * The destination type the buyer chose for an order (CA-48). Kept per order, not per address: Sylius clones
 * the order's addresses into the customer's address book, and a type kept on the address would not follow
 * the clone (D-28).
 */
interface CarrierOrderDestinationInterface extends ResourceInterface
{
    public function getOrder(): ?OrderInterface;

    public function setOrder(?OrderInterface $order): void;

    /** A DestinationType value. */
    public function getType(): ?string;

    public function setType(?string $type): void;
}
