<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label;

/**
 * What the commercial invoice of a shipment says: the carrier prints it from this, in the same request that
 * issues the labels.
 */
final readonly class CustomsInvoice
{
    /**
     * @param string $number The order's number
     * @param \DateTimeImmutable $date The day it is issued, which is the day the parcel is handed over
     * @param string $currencyCode What the order was paid in, and so what every amount is in
     * @param non-empty-list<CustomsItem> $lines Everything the shipment carries, across all its packages
     */
    public function __construct(
        public string $number,
        public \DateTimeImmutable $date,
        public string $currencyCode,
        public array $lines,
    ) {
    }

    /**
     * What the whole shipment is declared to be worth, in hundredths.
     */
    public function total(): int
    {
        return array_sum(array_map(static fn (CustomsItem $line): int => $line->quantity * $line->unitValue, $this->lines));
    }
}
