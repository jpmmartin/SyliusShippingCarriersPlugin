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
     */
    public function __construct(
        public string $hsCode,
        public string $countryOfOrigin,
        public string $description,
        public int $quantity,
        public int $unitValue,
        public string $currencyCode,
        public string $code,
    ) {
    }
}
