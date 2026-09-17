<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Unit\StoreUnitsResolverInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class WithinCarrierSizeLimitsValidator extends ConstraintValidator
{
    /** Maximum outer length, in inches, published by UPS and by FedEx Ground and Express. */
    public const MAX_LENGTH_IN = 108.0;

    /** Maximum outer length plus girth, in inches. */
    public const MAX_LENGTH_PLUS_GIRTH_IN = 165.0;

    private const CENTIMETRES_PER_INCH = 2.54;

    public function __construct(
        private readonly StoreUnitsResolverInterface $storeUnits,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, WithinCarrierSizeLimits::class);

        if (!$value instanceof CarrierPackageBoxInterface) {
            return;
        }

        $length = $value->getOuterLength();
        $width = $value->getOuterWidth();
        $height = $value->getOuterHeight();
        if (null === $length || null === $width || null === $height) {
            // Missing measures are reported by NotBlank.
            return;
        }

        $inCentimetres = CarrierShippingOriginInterface::DIMENSION_UNIT_CM === $this->storeUnits->getDimensionUnit();
        $toInches = $inCentimetres ? 1 / self::CENTIMETRES_PER_INCH : 1.0;

        // The carriers measure length along the longest side, and girth around the other two.
        $sides = ['outerLength' => $length, 'outerWidth' => $width, 'outerHeight' => $height];
        arsort($sides);
        $longestField = (string) array_key_first($sides);
        [$longest, $second, $third] = array_map(static fn (float $side): float => $side * $toInches, array_values($sides));

        $unit = $inCentimetres ? CarrierShippingOriginInterface::DIMENSION_UNIT_CM : CarrierShippingOriginInterface::DIMENSION_UNIT_IN;

        if ($longest > self::MAX_LENGTH_IN) {
            $this->context->buildViolation($constraint->lengthMessage)
                ->atPath($longestField)
                ->setParameter('{{ limit }}', $this->format(self::MAX_LENGTH_IN, $inCentimetres))
                ->setParameter('{{ unit }}', $unit)
                ->addViolation()
            ;
        }

        if ($longest + 2 * ($second + $third) > self::MAX_LENGTH_PLUS_GIRTH_IN) {
            $this->context->buildViolation($constraint->lengthPlusGirthMessage)
                ->atPath($longestField)
                ->setParameter('{{ limit }}', $this->format(self::MAX_LENGTH_PLUS_GIRTH_IN, $inCentimetres))
                ->setParameter('{{ unit }}', $unit)
                ->addViolation()
            ;
        }
    }

    private function format(float $inches, bool $inCentimetres): string
    {
        $value = $inCentimetres ? $inches * self::CENTIMETRES_PER_INCH : $inches;

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
