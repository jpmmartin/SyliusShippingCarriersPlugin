<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Customs\HsCodeNormalizer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** @internal */
final class HsCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof HsCode) {
            throw new UnexpectedTypeException($constraint, HsCode::class);
        }

        // An empty code is left to NotBlank: whether customs data is required is not this constraint's call.
        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (1 === preg_match('/^\d{6,10}$/', (string) HsCodeNormalizer::normalize($value))) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('%hs_code%', $value)
            ->addViolation()
        ;
    }
}
