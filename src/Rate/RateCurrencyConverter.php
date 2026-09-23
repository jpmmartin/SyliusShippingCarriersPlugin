<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

use Psr\Log\LoggerInterface;
use Sylius\Component\Currency\Converter\CurrencyConverterInterface;
use Sylius\Component\Currency\Model\ExchangeRateInterface;
use Sylius\Component\Currency\Repository\ExchangeRateRepositoryInterface;

/**
 * Carriers rate in the currency of the merchant's account, which need not be the currency of the order. Such a
 * rate is converted with the exchange rate the store has for the pair.
 *
 * @internal
 */
final readonly class RateCurrencyConverter
{
    /**
     * @param ExchangeRateRepositoryInterface<ExchangeRateInterface> $exchangeRateRepository
     */
    public function __construct(
        private ExchangeRateRepositoryInterface $exchangeRateRepository,
        private CurrencyConverterInterface $currencyConverter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Null, with the reason logged, when the store has no exchange rate for the pair.
     */
    public function convert(Rate $rate, string $currencyCode): ?Rate
    {
        if ($rate->currencyCode === $currencyCode) {
            return $rate;
        }

        // Sylius's converter hands the amount back unconverted when there is no exchange rate, which would
        // charge a rate in dollars as the same number of euros. So the pair is looked up first.
        if (null === $this->exchangeRateRepository->findOneWithCurrencyPair($rate->currencyCode, $currencyCode)) {
            $this->logger->error('The service {service} is rated in {rate_currency} and there is no exchange rate to {order_currency}, so it cannot be quoted.', [
                'service' => $rate->serviceCode,
                'rate_currency' => $rate->currencyCode,
                'order_currency' => $currencyCode,
            ]);

            return null;
        }

        return new Rate(
            $rate->serviceCode,
            $this->currencyConverter->convert($rate->amount, $rate->currencyCode, $currencyCode),
            $currencyCode,
        );
    }
}
