<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox;
use JpmMartin\SyliusShippingCarriersPlugin\Unit\StoreUnitsResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\WithinCarrierSizeLimits;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\WithinCarrierSizeLimitsValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<WithinCarrierSizeLimitsValidator>
 */
final class WithinCarrierSizeLimitsValidatorTest extends ConstraintValidatorTestCase
{
    private string $dimensionUnit = 'in';

    protected function createValidator(): WithinCarrierSizeLimitsValidator
    {
        $storeUnits = $this->createStub(StoreUnitsResolverInterface::class);
        $storeUnits->method('getDimensionUnit')->willReturnCallback(fn (): string => $this->dimensionUnit);

        return new WithinCarrierSizeLimitsValidator($storeUnits);
    }

    public function testABoxWithinTheLimitsPasses(): void
    {
        $this->validator->validate($this->box(13.0, 11.0, 9.0), new WithinCarrierSizeLimits());

        $this->assertNoViolation();
    }

    public function testABoxExactlyAtBothLimitsPasses(): void
    {
        // 108 + 2 × (14.25 + 14.25) = 165.
        $this->validator->validate($this->box(108.0, 14.25, 14.25), new WithinCarrierSizeLimits());

        $this->assertNoViolation();
    }

    public function testABoxExactlyAtBothLimitsInCentimetresPasses(): void
    {
        $this->dimensionUnit = 'cm';

        // The same box as above: 274.32 cm is 108", 36.195 cm is 14.25".
        $this->validator->validate($this->box(274.32, 36.195, 36.195), new WithinCarrierSizeLimits());

        $this->assertNoViolation();
    }

    public function testATooLongBoxIsReportedOnItsLongestSide(): void
    {
        $this->validator->validate($this->box(10.0, 120.0, 10.0), new WithinCarrierSizeLimits());

        $this->buildViolation('jpmmartin_carrier.package_box.outer_length.too_long')
            ->atPath('property.path.outerWidth')
            ->setParameter('{{ limit }}', '108')
            ->setParameter('{{ unit }}', 'in')
            ->assertRaised()
        ;
    }

    public function testTheLimitIsReportedInCentimetresInAStoreInCentimetres(): void
    {
        $this->dimensionUnit = 'cm';

        // 280 cm is about 110".
        $this->validator->validate($this->box(280.0, 10.0, 10.0), new WithinCarrierSizeLimits());

        $this->buildViolation('jpmmartin_carrier.package_box.outer_length.too_long')
            ->atPath('property.path.outerLength')
            ->setParameter('{{ limit }}', '274.32')
            ->setParameter('{{ unit }}', 'cm')
            ->assertRaised()
        ;
    }

    public function testATooLargeBoxIsRejected(): void
    {
        // 100 + 2 × (20 + 20) = 180.
        $this->validator->validate($this->box(100.0, 20.0, 20.0), new WithinCarrierSizeLimits());

        $this->buildViolation('jpmmartin_carrier.package_box.outer_length.length_plus_girth_too_large')
            ->atPath('property.path.outerLength')
            ->setParameter('{{ limit }}', '165')
            ->setParameter('{{ unit }}', 'in')
            ->assertRaised()
        ;
    }

    public function testTheGirthIsMeasuredAroundTheTwoShorterSides(): void
    {
        // Along its longest side: 80 + 2 × (20 + 20) = 160. Taking the 20 entered as length would give 220.
        $this->validator->validate($this->box(20.0, 80.0, 20.0), new WithinCarrierSizeLimits());

        $this->assertNoViolation();
    }

    public function testMissingMeasuresAreLeftToNotBlank(): void
    {
        $this->validator->validate($this->box(null, 200.0, 200.0), new WithinCarrierSizeLimits());

        $this->assertNoViolation();
    }

    private function box(?float $outerLength, float $outerWidth, float $outerHeight): CarrierPackageBox
    {
        $box = new CarrierPackageBox();
        $box->setOuterLength($outerLength);
        $box->setOuterWidth($outerWidth);
        $box->setOuterHeight($outerHeight);

        return $box;
    }
}
