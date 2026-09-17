<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateCurrencyConverter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Sylius\Component\Currency\Converter\CurrencyConverter;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Currency\Model\ExchangeRate;
use Sylius\Component\Currency\Repository\ExchangeRateRepositoryInterface;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\RecordingLogger;

final class RateCurrencyConverterTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testARateInTheCurrencyOfTheOrderIsKept(): void
    {
        $rate = new Rate('03', 1540, 'USD');

        self::assertSame($rate, $this->converter(null)->convert($rate, 'USD'));
    }

    public function testARateIsConvertedWithTheExchangeRateOfThePair(): void
    {
        $converter = $this->converter($this->exchangeRate('USD', 'EUR', 0.9));

        self::assertEquals(new Rate('03', 1386, 'EUR'), $converter->convert(new Rate('03', 1540, 'USD'), 'EUR'));
    }

    /**
     * Sylius keeps one exchange rate per pair, in either direction.
     */
    public function testARateIsConvertedWithTheExchangeRateOfTheReversedPair(): void
    {
        $converter = $this->converter($this->exchangeRate('EUR', 'USD', 1.1));

        self::assertEquals(new Rate('03', 1400, 'EUR'), $converter->convert(new Rate('03', 1540, 'USD'), 'EUR'));
    }

    public function testWithoutAnExchangeRateNothingIsQuotedAndTheReasonIsLogged(): void
    {
        self::assertNull($this->converter(null)->convert(new Rate('03', 1540, 'USD'), 'EUR'));

        self::assertCount(1, $this->logger->records);
        [$level, $message, $context] = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $level);
        self::assertStringContainsString('no exchange rate', $message);
        self::assertSame(['service' => '03', 'rate_currency' => 'USD', 'order_currency' => 'EUR'], $context);
    }

    private function converter(?ExchangeRate $exchangeRate): RateCurrencyConverter
    {
        $repository = $this->createStub(ExchangeRateRepositoryInterface::class);
        $repository->method('findOneWithCurrencyPair')->willReturn($exchangeRate);

        return new RateCurrencyConverter($repository, new CurrencyConverter($repository), $this->logger);
    }

    private function exchangeRate(string $source, string $target, float $ratio): ExchangeRate
    {
        $sourceCurrency = new Currency();
        $sourceCurrency->setCode($source);
        $targetCurrency = new Currency();
        $targetCurrency->setCode($target);

        $exchangeRate = new ExchangeRate();
        $exchangeRate->setSourceCurrency($sourceCurrency);
        $exchangeRate->setTargetCurrency($targetCurrency);
        $exchangeRate->setRatio($ratio);

        return $exchangeRate;
    }
}
