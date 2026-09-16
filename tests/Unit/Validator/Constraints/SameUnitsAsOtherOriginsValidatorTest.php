<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\SameUnitsAsOtherOrigins;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\SameUnitsAsOtherOriginsValidator;
use PHPUnit\Framework\MockObject\Stub;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<SameUnitsAsOtherOriginsValidator>
 */
final class SameUnitsAsOtherOriginsValidatorTest extends ConstraintValidatorTestCase
{
    /** @var list<CarrierShippingOriginInterface> */
    private array $storedOrigins = [];

    protected function createValidator(): SameUnitsAsOtherOriginsValidator
    {
        /** @var RepositoryInterface<CarrierShippingOriginInterface>&Stub $repository */
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findBy')->willReturnCallback(fn (): array => $this->storedOrigins);

        return new SameUnitsAsOtherOriginsValidator($repository);
    }

    public function testTheFirstOriginMayUseAnyUnits(): void
    {
        $this->validator->validate($this->origin('kg', 'cm'), new SameUnitsAsOtherOrigins());

        $this->assertNoViolation();
    }

    public function testTheOnlyOriginMayChangeItsUnits(): void
    {
        $origin = $this->origin('lb', 'in');
        $this->storedOrigins = [$origin];

        $origin->setWeightUnit('kg');
        $origin->setDimensionUnit('cm');
        $this->validator->validate($origin, new SameUnitsAsOtherOrigins());

        $this->assertNoViolation();
    }

    public function testAnOriginWithTheUnitsOfTheOthersPasses(): void
    {
        $this->storedOrigins = [$this->origin('kg', 'cm')];

        $this->validator->validate($this->origin('kg', 'cm'), new SameUnitsAsOtherOrigins());

        $this->assertNoViolation();
    }

    public function testANewOriginWithOtherUnitsIsRejectedOnBothUnits(): void
    {
        $this->storedOrigins = [$this->origin('lb', 'in')];

        $this->validator->validate($this->origin('kg', 'cm'), new SameUnitsAsOtherOrigins());

        $this->buildViolation('jpmmartin_carrier.shipping_origin.weight_unit.same_as_other_origins')
            ->atPath('property.path.weightUnit')
            ->setParameter('{{ unit }}', 'lb')
            ->buildNextViolation('jpmmartin_carrier.shipping_origin.dimension_unit.same_as_other_origins')
            ->atPath('property.path.dimensionUnit')
            ->setParameter('{{ unit }}', 'in')
            ->assertRaised()
        ;
    }

    /**
     * The trade-off D-16 accepts: with two or more origins, the store's units cannot change from one of them.
     */
    public function testOneOfSeveralOriginsCannotChangeItsUnits(): void
    {
        $edited = $this->origin('lb', 'in');
        $this->storedOrigins = [$edited, $this->origin('lb', 'in')];

        $edited->setDimensionUnit('cm');
        $this->validator->validate($edited, new SameUnitsAsOtherOrigins());

        $this->buildViolation('jpmmartin_carrier.shipping_origin.dimension_unit.same_as_other_origins')
            ->atPath('property.path.dimensionUnit')
            ->setParameter('{{ unit }}', 'in')
            ->assertRaised()
        ;
    }

    private function origin(string $weightUnit, string $dimensionUnit): CarrierShippingOrigin
    {
        $origin = new CarrierShippingOrigin();
        $origin->setWeightUnit($weightUnit);
        $origin->setDimensionUnit($dimensionUnit);

        return $origin;
    }
}
