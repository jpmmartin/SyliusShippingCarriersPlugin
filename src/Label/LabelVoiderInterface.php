<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\NotIssuedException;

/**
 * Cancels the labels of a shipment with the carrier that issued them.
 */
interface LabelVoiderInterface
{
    /**
     * @param string $voidedBy Who asked for it, as it is shown afterwards
     *
     * @return VoidResult What the carrier answered. A refusal is an answer, not a failure: the labels stay
     *                    issued and the reason is kept
     *
     * @throws NotIssuedException When there are no issued labels to cancel
     */
    public function void(CarrierShipmentExportInterface $export, string $voidedBy): VoidResult;
}
