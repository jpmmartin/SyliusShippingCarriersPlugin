<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Rate;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Rates come from the cache while they are fresh and from the carrier otherwise.
 *
 * Every answer of a carrier is kept with two ages. While younger than the lifetime it is quoted. After that it
 * is only the last known rate, charged when the carrier fails, and it is dropped after the retention.
 *
 * @internal
 */
final class RateProvider implements RateProviderInterface, ResetInterface
{
    /**
     * The keys whose carrier call failed in this request. The other services of the same shipment fail with it
     * instead of calling again, so a carrier that is down costs one wait and not one per service.
     *
     * @var array<string, true>
     */
    private array $failedKeys = [];

    /**
     * The carriers whose credentials could not be read in this request. They do not change while it lasts, so the
     * store is told once and not once per service asked.
     *
     * @var array<string, true>
     */
    private array $carriersLoggedWithoutCredentials = [];

    /** Whether the store was told in this request that the settings cannot be used. */
    private bool $unusableSettingsLogged = false;

    /**
     * @param ContainerInterface $carriers The carrier adapters, by carrier code
     */
    public function __construct(
        private readonly RateRequestFactory $requestFactory,
        private readonly CredentialsProvider $credentialsProvider,
        private readonly ContainerInterface $carriers,
        private readonly CacheItemPoolInterface $cache,
        private readonly RateCurrencyConverter $currencyConverter,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly CarrierSettingsProvider $settings,
    ) {
    }

    public function rateFor(ShipmentInterface $shipment, string $carrier, string $serviceCode): RateResult
    {
        if (!$this->carriers->has($carrier)) {
            throw new \InvalidArgumentException(sprintf('There is no carrier "%s".', $carrier));
        }

        $order = $shipment->getOrder();
        $currencyCode = $order instanceof OrderInterface ? $order->getCurrencyCode() : null;
        $request = null === $currencyCode ? null : $this->requestFactory->create($shipment);
        if (null === $currencyCode || null === $request) {
            return RateResult::unavailable();
        }

        // As with an origin that is missing: no carrier is asked and the method is not offered.
        $channel = $order instanceof OrderInterface ? $order->getChannel() : null;
        $settings = $this->settings->forChannel($channel);

        try {
            $settings->assertRatesUsable();
        } catch (InvalidCarrierSettingException $exception) {
            $this->logUnusableSettings($exception);

            return RateResult::unavailable();
        }

        try {
            $credentials = $this->credentialsProvider->get($carrier);
        } catch (CarrierException $exception) {
            $this->logCredentialsFailure($carrier, $serviceCode, $exception);

            return RateResult::carrierFailed(null);
        }

        $key = RateCacheKey::for($carrier, self::pricingConfiguration($credentials), $request, $currencyCode, $channel?->getCode());
        $item = $this->cache->getItem($key);
        $now = $this->clock->now()->getTimestamp();

        $stored = $item->isHit() ? StoredRates::fromCacheValue($item->get()) : null;
        if (null !== $stored && $now - $stored->fetchedAt >= $settings->rateRetention) {
            $stored = null;
        }

        if (null !== $stored && $now - $stored->fetchedAt < $settings->rateLifetime) {
            return $this->quote($stored->rates, $serviceCode, $currencyCode);
        }

        if (isset($this->failedKeys[$key])) {
            return $this->carrierFailed($stored, $serviceCode, $currencyCode);
        }

        $carrierAdapter = $this->carriers->get($carrier);
        if (!$carrierAdapter instanceof CarrierInterface) {
            throw new \LogicException(sprintf('The carrier "%s" does not implement %s.', $carrier, CarrierInterface::class));
        }

        try {
            $rates = $carrierAdapter->rate($request);
        } catch (CarrierException $exception) {
            // Inside the catch, so a failure remembered for the rest of the request is told once and not once per
            // service asked. The adapters do not log it: they only translate the exception.
            $this->logger->error('The carrier {carrier} could not rate the service {service}: {reason}', [
                'carrier' => $carrier,
                'service' => $serviceCode,
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->failedKeys[$key] = true;

            return $this->carrierFailed($stored, $serviceCode, $currencyCode);
        }

        $item->set((new StoredRates($rates, $now))->toCacheValue());
        $item->expiresAfter($settings->rateRetention);
        $this->cache->save($item);

        return $this->quote($rates, $serviceCode, $currencyCode);
    }

    public function reset(): void
    {
        $this->failedKeys = [];
        $this->carriersLoggedWithoutCredentials = [];
        $this->unusableSettingsLogged = false;
    }

    private function logUnusableSettings(InvalidCarrierSettingException $exception): void
    {
        if ($this->unusableSettingsLogged) {
            return;
        }

        $this->logger->error('No carrier is asked for rates, and no shipping method of the plugin is offered, because a setting cannot be used: {reason}', [
            'reason' => $exception->getMessage(),
            'exception' => $exception,
        ]);
        $this->unusableSettingsLogged = true;
    }

    private function logCredentialsFailure(string $carrier, string $serviceCode, CarrierException $exception): void
    {
        if (isset($this->carriersLoggedWithoutCredentials[$carrier])) {
            return;
        }

        $this->carriersLoggedWithoutCredentials[$carrier] = true;
        $this->logger->error('The credentials of the carrier {carrier} could not be read, so the service {service} could not be rated: {reason}', [
            'carrier' => $carrier,
            'service' => $serviceCode,
            'reason' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }

    private function quote(RateSet $rates, string $serviceCode, string $currencyCode): RateResult
    {
        $rate = $rates->get($serviceCode);
        $rate = null === $rate ? null : $this->currencyConverter->convert($rate, $currencyCode);

        return null === $rate ? RateResult::unavailable() : RateResult::quoted($rate);
    }

    private function carrierFailed(?StoredRates $stored, string $serviceCode, string $currencyCode): RateResult
    {
        $lastKnownRate = $stored?->rates->get($serviceCode);

        return RateResult::carrierFailed(null === $lastKnownRate ? null : $this->currencyConverter->convert($lastKnownRate, $currencyCode));
    }

    /**
     * What else changes the price of the carrier. Never the secret.
     *
     * @return array<string, string|null>
     */
    private static function pricingConfiguration(CarrierCredentialsInterface $credentials): array
    {
        return [
            'environment' => $credentials->getEnvironment(),
            'pickup_type' => $credentials->getPickupType(),
            'account_number' => $credentials->getCredentials()[CarrierCredentialsInterface::ACCOUNT_NUMBER] ?? null,
        ];
    }
}
