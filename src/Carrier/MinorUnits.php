<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use Symfony\Component\Intl\Currencies;

/**
 * Turns an amount a carrier writes in decimals into the minor units Sylius keeps, without going through a
 * float.
 */
final class MinorUnits
{
    /**
     * @throws \InvalidArgumentException When the amount is not a decimal number
     */
    public static function fromDecimal(string $amount, string $currencyCode): int
    {
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($amount), $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a decimal amount.', $amount));
        }

        $digits = Currencies::getFractionDigits($currencyCode);
        // One digit past the currency's precision decides the rounding, half up.
        $fraction = str_pad($matches[3] ?? '', $digits + 1, '0');
        $minor = (int) ($matches[2] . substr($fraction, 0, $digits));
        if ((int) $fraction[$digits] >= 5) {
            ++$minor;
        }

        return '-' === $matches[1] ? -$minor : $minor;
    }
}
