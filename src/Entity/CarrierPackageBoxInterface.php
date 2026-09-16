<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;

/**
 * A box from the installation-wide catalog (CA-34, CA-35). Its measures carry no unit of their own:
 * they are read in the length and weight units of the origin that uses the box (CA-41).
 */
interface CarrierPackageBoxInterface extends ResourceInterface
{
    public function getName(): ?string;

    public function setName(?string $name): void;

    /** Inner measures decide whether the content fits (CA-36). */
    public function getInnerLength(): ?float;

    public function setInnerLength(?float $innerLength): void;

    public function getInnerWidth(): ?float;

    public function setInnerWidth(?float $innerWidth): void;

    public function getInnerHeight(): ?float;

    public function setInnerHeight(?float $innerHeight): void;

    /** Outer measures are the ones declared to the carrier (CA-36). */
    public function getOuterLength(): ?float;

    public function setOuterLength(?float $outerLength): void;

    public function getOuterWidth(): ?float;

    public function setOuterWidth(?float $outerWidth): void;

    public function getOuterHeight(): ?float;

    public function setOuterHeight(?float $outerHeight): void;

    /** Tare, added to the content's weight (CA-37). */
    public function getEmptyWeight(): ?float;

    public function setEmptyWeight(?float $emptyWeight): void;

    public function getMaxWeight(): ?float;

    public function setMaxWeight(?float $maxWeight): void;
}
