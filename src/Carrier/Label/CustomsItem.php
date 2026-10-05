<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

/**
 * One line of what customs is told a package contains.
 */
final readonly class CustomsItem
{
    /**
     * @param string $hsCode The Harmonized System code, digits only
     * @param string $countryOfOrigin Two letters, where the goods were made
     * @param int $unitValue What one unit is worth, in hundredths, as Sylius keeps every amount
     * @param string $code The variant's code, which is how the invoice tells two lines of the same product apart
     * @param float|null $weight What the whole line weighs, its units included, in $weightUnit. FedEx refuses a line
     *                           without it
     * @param string|null $weightUnit «lb» or «kg», the unit the shipping origin weighs in
     */
    public function __construct(
        public string $hsCode,
        public string $countryOfOrigin,
        public string $description,
        public int $quantity,
        public int $unitValue,
        public string $currencyCode,
        public string $code,
        public ?float $weight = null,
        public ?string $weightUnit = null,
    ) {
    }
}
