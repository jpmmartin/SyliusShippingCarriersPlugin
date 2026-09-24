<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Tracking;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\Exception\InvalidCarrierSettingException;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\ShipmentCarrier;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Asks the carrier where a shipment is, and keeps the answer for a short while: opening the same order again does
 * not ask the carrier again.
 *
 * @internal
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
     * The carriers whose credentials could not be read in this request. They do not change while it lasts, so the
     * store is told once and not once per shipment of the page.
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
        private readonly ShipmentCarrier $shipmentCarrier,
        private readonly CredentialsProvider $credentialsProvider,
        private readonly ContainerInterface $carriers,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly CarrierSettingsProvider $settings,
    ) {
    }

    public function track(ShipmentInterface $shipment): ?TrackingInfo
    {
        $trackingNumber = $shipment->getTracking();
        $carrier = $this->shipmentCarrier->of($shipment);
        if (null === $trackingNumber || '' === trim($trackingNumber) || null === $carrier) {
            return null;
        }

        // Shown as a status that is not available, the way a carrier that does not answer is.
        $order = $shipment->getOrder();
        $settings = $this->settings->forChannel($order instanceof OrderInterface ? $order->getChannel() : null);

        try {
            $settings->assertTrackingUsable();
        } catch (InvalidCarrierSettingException $exception) {
            $this->logUnusableSettings($exception);

            return null;
        }

        try {
            $credentials = $this->credentialsProvider->get($carrier);
        } catch (CarrierException $exception) {
            $this->logCredentialsFailure($carrier, $trackingNumber, $exception);

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
        } catch (CarrierException $exception) {
            // Inside the catch, so a failure remembered for the rest of the request is told once and not once per
            // shipment of the page. The adapters do not log it: they only translate the exception.
            $this->logger->error('The carrier {carrier} could not be asked where the shipment {tracking_number} is: {reason}', [
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
                'reason' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            // Not stored: the buyer would be left without a status for minutes after the carrier recovered.
            $this->failedKeys[$key] = true;

            return null;
        }

        $item->set(StoredTracking::toCacheValue($tracking));
        $item->expiresAfter($settings->trackingLifetime);
        $this->cache->save($item);

        return $tracking;
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

        $this->logger->error('No carrier is asked where a shipment is, because a setting cannot be used: {reason}', [
            'reason' => $exception->getMessage(),
            'exception' => $exception,
        ]);
        $this->unusableSettingsLogged = true;
    }

    private function logCredentialsFailure(string $carrier, string $trackingNumber, CarrierException $exception): void
    {
        if (isset($this->carriersLoggedWithoutCredentials[$carrier])) {
            return;
        }

        $this->carriersLoggedWithoutCredentials[$carrier] = true;
        $this->logger->error('The credentials of the carrier {carrier} could not be read, so nobody could be asked where the shipment {tracking_number} is: {reason}', [
            'carrier' => $carrier,
            'tracking_number' => $trackingNumber,
            'reason' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
