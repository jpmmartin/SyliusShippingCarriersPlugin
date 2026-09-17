<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Asks the carrier where a shipment is, and keeps the answer for a short while: opening the same order again does
 * not ask the carrier again.
 */
final class TrackingProvider implements TrackingProviderInterface, ResetInterface
{
    /**
     * The keys whose carrier call failed in this request, so a page showing several shipments of a carrier that is
     * down costs one wait and not one per shipment.
     *
     * @var array<string, true>
     */
    private array $failedKeys = [];

    /**
     * @param ContainerInterface $carriers The carrier adapters, by carrier code
     * @param int $lifetime Seconds a stored status is given before the carrier is asked again
     */
    public function __construct(
        private readonly ServiceRegistryInterface $calculators,
        private readonly CredentialsProvider $credentialsProvider,
        private readonly ContainerInterface $carriers,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $lifetime,
    ) {
    }

    public function track(ShipmentInterface $shipment): ?TrackingInfo
    {
        $trackingNumber = $shipment->getTracking();
        $carrier = $this->carrierOf($shipment);
        if (null === $trackingNumber || '' === trim($trackingNumber) || null === $carrier) {
            return null;
        }

        try {
            $credentials = $this->credentialsProvider->get($carrier);
        } catch (CarrierException) {
            return null;
        }

        // The sandbox of a carrier tracks shipments of its own, so its answers are kept apart.
        $key = 'tracking_' . hash('xxh128', json_encode([$carrier, (string) $credentials->getEnvironment(), $trackingNumber], \JSON_THROW_ON_ERROR));
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            $stored = StoredTracking::fromCacheValue($item->get(), $trackingNumber);
            if (null !== $stored) {
                return $stored;
            }
        }

        if (isset($this->failedKeys[$key])) {
            return null;
        }

        $adapter = $this->carriers->has($carrier) ? $this->carriers->get($carrier) : null;
        if (!$adapter instanceof CarrierInterface) {
            return null;
        }

        try {
            $tracking = $adapter->track($trackingNumber);
        } catch (CarrierException) {
            // Not stored: the buyer would be left without a status for minutes after the carrier recovered.
            $this->failedKeys[$key] = true;

            return null;
        }

        $item->set(StoredTracking::toCacheValue($tracking));
        $item->expiresAfter($this->lifetime);
        $this->cache->save($item);

        return $tracking;
    }

    public function reset(): void
    {
        $this->failedKeys = [];
    }

    private function carrierOf(ShipmentInterface $shipment): ?string
    {
        $calculatorName = $shipment->getMethod()?->getCalculator();
        $calculator = null !== $calculatorName && $this->calculators->has($calculatorName) ? $this->calculators->get($calculatorName) : null;

        return $calculator instanceof CarrierRateCalculator ? $calculator->getCarrier() : null;
    }
}
