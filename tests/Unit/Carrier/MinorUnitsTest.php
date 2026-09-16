<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\MinorUnits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinorUnitsTest extends TestCase
{
    #[DataProvider('amounts')]
    public function testItTurnsADecimalAmountIntoMinorUnits(string $amount, string $currencyCode, int $expected): void
    {
        self::assertSame($expected, MinorUnits::fromDecimal($amount, $currencyCode));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function amounts(): iterable
    {
        yield 'two decimals' => ['15.40', 'USD', 1540];
        yield 'one decimal' => ['15.4', 'USD', 1540];
        yield 'no decimals' => ['15', 'USD', 1500];
        yield 'a third decimal of 5 rounds up' => ['12.345', 'USD', 1235];
        yield 'a third decimal of 4 rounds down' => ['12.344', 'USD', 1234];
        yield 'a currency without decimals' => ['1500', 'JPY', 1500];
        yield 'a decimal in a currency without them rounds' => ['1500.5', 'JPY', 1501];
        yield 'a negative amount' => ['-3.10', 'EUR', -310];
    }

    public function testSomethingThatIsNotADecimalAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MinorUnits::fromDecimal('12,34', 'EUR');
    }
}
