<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

/** @internal */
final class SameUnitsAsOtherOriginsValidator extends ConstraintValidator
{
    /** @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository */
    public function __construct(
        private readonly RepositoryInterface $originRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, SameUnitsAsOtherOrigins::class);

        if (!$value instanceof CarrierShippingOriginInterface) {
            return;
        }

        foreach ($this->originRepository->findBy([], ['id' => 'ASC']) as $other) {
            // An edited origin is the managed instance the repository returns, so identity tells it apart.
            if (!$other instanceof CarrierShippingOriginInterface || $other === $value) {
                continue;
            }

            // All the others share their units already, so the first one tells.
            if ($other->getWeightUnit() !== $value->getWeightUnit()) {
                $this->context->buildViolation($constraint->weightUnitMessage)
                    ->atPath('weightUnit')
                    ->setParameter('{{ unit }}', $other->getWeightUnit())
                    ->addViolation()
                ;
            }

            if ($other->getDimensionUnit() !== $value->getDimensionUnit()) {
                $this->context->buildViolation($constraint->dimensionUnitMessage)
                    ->atPath('dimensionUnit')
                    ->setParameter('{{ unit }}', $other->getDimensionUnit())
                    ->addViolation()
                ;
            }

            return;
        }
    }
}
