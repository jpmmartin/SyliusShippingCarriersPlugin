<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\MinorUnits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinorUnitsTest extends TestCase
{
    #[DataProvider('amounts')]
    public function testItTurnsADecimalAmountIntoHundredths(string $amount, int $expected): void
    {
        self::assertSame($expected, MinorUnits::fromDecimal($amount));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function amounts(): iterable
    {
        yield 'two decimals' => ['15.40', 1540];
        yield 'one decimal' => ['15.4', 1540];
        yield 'no decimals' => ['15', 1500];
        yield 'a third decimal of 5 rounds up' => ['12.345', 1235];
        yield 'a third decimal of 4 rounds down' => ['12.344', 1234];
        // Sylius keeps hundredths even for a currency without decimals, such as the yen.
        yield 'an amount of a currency without decimals' => ['1500', 150000];
        yield 'a negative amount' => ['-3.10', -310];
    }

    public function testSomethingThatIsNotADecimalAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MinorUnits::fromDecimal('12,34');
    }
}
