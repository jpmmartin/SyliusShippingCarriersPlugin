<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use Sylius\Component\Core\Model\OrderInterface;

/**
 * Turns an amount a carrier writes in decimals into the integer Sylius keeps, without going through a float.
 *
 * Sylius keeps every amount in hundredths, whatever the currency: its money formatter divides by 100 and its
 * money form type uses a divisor of 100. So ¥1,500 is kept as 150000, not 1500.
 *
 * @internal
 */
final class MinorUnits
{
    private const DIGITS = 2;

    /**
     * @throws \InvalidArgumentException When the amount is not a decimal number
     */
    public static function fromDecimal(string $amount): int
    {
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($amount), $matches)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a decimal amount.', $amount));
        }

        // One digit past the kept precision decides the rounding, half up.
        $fraction = str_pad($matches[3] ?? '', self::DIGITS + 1, '0');
        $minor = (int) ($matches[2] . substr($fraction, 0, self::DIGITS));
        if ((int) $fraction[self::DIGITS] >= 5) {
            ++$minor;
        }

        return '-' === $matches[1] ? -$minor : $minor;
    }
}
