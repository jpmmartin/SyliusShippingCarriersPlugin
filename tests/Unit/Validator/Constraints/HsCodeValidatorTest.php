<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\HsCode;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\HsCodeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<HsCodeValidator>
 */
final class HsCodeValidatorTest extends ConstraintValidatorTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedCodes(): iterable
    {
        yield 'the six digits the whole world shares' => ['691200'];
        yield 'ten digits, the longest a tariff goes' => ['6912001000'];
        yield 'eight digits, as the European tariff writes them' => ['69120021'];
        yield 'written with dots, as a supplier prints it' => ['6912.00.21'];
        yield 'written with spaces' => ['6912 00 21'];
        yield 'written with hyphens' => ['6912-00-21'];
        yield 'with spaces around it' => ['  691200  '];
    }

    #[DataProvider('acceptedCodes')]
    public function testACodeCustomsWouldRecogniseIsAccepted(string $hsCode): void
    {
        $this->validator->validate($hsCode, new HsCode());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedCodes(): iterable
    {
        yield 'too short to name any goods' => ['69120'];
        yield 'longer than any tariff' => ['69120010001'];
        yield 'with letters in it' => ['6912AB'];
        yield 'a word' => ['mug'];
        yield 'only separators' => ['....'];
        yield 'digits and a stray symbol' => ['691200#'];
    }

    #[DataProvider('rejectedCodes')]
    public function testACodeCustomsWouldNotRecogniseIsRejected(string $hsCode): void
    {
        $this->validator->validate($hsCode, new HsCode());

        $this->buildViolation('jpmmartin_carrier.customs_data.hs_code.invalid')
            ->setParameter('%hs_code%', $hsCode)
            ->assertRaised()
        ;
    }

    /**
     * Whether the customs data is required at all is not this constraint's business.
     */
    public function testAnEmptyCodeIsLeftToNotBlank(): void
    {
        $this->validator->validate(null, new HsCode());
        $this->validator->validate('', new HsCode());

        $this->assertNoViolation();
    }

    protected function createValidator(): HsCodeValidator
    {
        return new HsCodeValidator();
    }
}
