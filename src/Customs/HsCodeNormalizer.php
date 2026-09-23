<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Customs;

/**
 * Catalogues are written by people, and people punctuate a Harmonized System code however their supplier did:
 * `6912.00`, `6912 00`, `6912-00`. They all name the same goods, so the separators are dropped and the code is
 * kept as the digits it is.
 *
 * @internal
 */
final class HsCodeNormalizer
{
    /**
     * Anything that is not a digit is left alone: a code with letters in it must reach the validator as the
     * administrator typed it, so the message can be about what they wrote.
     */
    public static function normalize(?string $hsCode): ?string
    {
        if (null === $hsCode) {
            return null;
        }

        $normalized = str_replace([' ', '.', '-', "\u{00a0}"], '', trim($hsCode));

        return '' === $normalized ? null : $normalized;
    }
}
