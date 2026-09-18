<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Model\ResourceInterface;

/**
 * What customs needs to know about a product variant: what it is and where it was made.
 */
interface CarrierCustomsDataInterface extends ResourceInterface
{
    public function getVariant(): ?ProductVariantInterface;

    public function setVariant(?ProductVariantInterface $variant): void;

    /**
     * The Harmonized System code that says what the goods are, as customs classifies them.
     */
    public function getHsCode(): ?string;

    public function setHsCode(?string $hsCode): void;

    /**
     * The two-letter code of the country the goods were made in, which is not always where they ship from.
     */
    public function getCountryOfOrigin(): ?string;

    public function setCountryOfOrigin(?string $countryOfOrigin): void;

    /**
     * Whether customs has everything it needs about this variant.
     */
    public function isComplete(): bool;
}
