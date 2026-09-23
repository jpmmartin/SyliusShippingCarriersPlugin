<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\AddressFactory;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsInvoice;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsItem;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Customs\CustomsDataProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Customs\DeclaredValueCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Customs\Exception\MissingCustomsDataException;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType;
use JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\UnissuableShipmentException;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * Turns a shipment into what a carrier is asked to print.
 *
 * The packages are the ones stored when the order was confirmed, read and never recomputed: what the buyer was
 * charged for and what goes on the van have to be the same parcels, and the catalogue may well have changed
 * since. A shipment whose packages were never stored is not issued at all.
 *
 * @internal
 */
final readonly class ShipmentRequestFactory
{
    /**
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     * @param RepositoryInterface<CarrierShipmentPackagingInterface> $packagingRepository
     */
    public function __construct(
        private RepositoryInterface $originRepository,
        private RepositoryInterface $packagingRepository,
        private DestinationTypeResolverInterface $destinationTypeResolver,
        private AddressFactory $addressFactory,
        private LabelFormats $labelFormats,
        private CustomsDataProvider $customsDataProvider,
        private DeclaredValueCalculator $declaredValueCalculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $carrier The carrier's code, such as `ups`
     * @param string $ownReference What the plugin calls this attempt, to ask the carrier about later
     *
     * @throws UnissuableShipmentException When the shipment cannot be handed to the carrier
     */
    public function create(ShipmentInterface $shipment, string $carrier, string $ownReference): ShipmentRequest
    {
        $order = $shipment->getOrder();
        if (!$order instanceof OrderInterface) {
            throw new UnissuableShipmentException('The shipment does not belong to an order.');
        }

        $channel = $order->getChannel();
        $origin = null === $channel ? null : $this->originRepository->findOneBy(['channel' => $channel]);
        if (!$origin instanceof CarrierShippingOriginInterface) {
            throw new UnissuableShipmentException(sprintf('The channel "%s" has no shipping origin to send from.', (string) $channel?->getCode()));
        }

        $originAddress = $this->addressFactory->forOrigin($origin);
        if (null === $originAddress) {
            throw new UnissuableShipmentException(sprintf('The shipping origin of the channel "%s" is incomplete.', (string) $channel?->getCode()));
        }

        $destination = $this->addressFactory->forDestination(
            $order->getShippingAddress(),
            DestinationType::RESIDENTIAL === $this->destinationTypeResolver->resolve($order),
        );
        if (null === $destination) {
            throw new UnissuableShipmentException('The order has no complete shipping address.');
        }

        $configuration = $shipment->getMethod()?->getConfiguration() ?? [];
        $serviceCode = $configuration[CarrierRateCalculator::SERVICE] ?? null;
        if (!is_string($serviceCode) || '' === $serviceCode) {
            throw new UnissuableShipmentException('The shipping method of the shipment says no carrier service to send it by.');
        }

        // Nothing is declared for a parcel that never leaves its country, and nothing is demanded of the
        // catalogue for it either.
        $crossesABorder = $originAddress->countryCode !== $destination->countryCode;
        $packages = $this->packages($shipment, $crossesABorder ? (string) $order->getCurrencyCode() : null);

        return new ShipmentRequest(
            $originAddress,
            $destination,
            $serviceCode,
            $packages,
            $this->labelFormats->for($carrier),
            $ownReference,
            $crossesABorder ? $this->invoice($order, $packages) : null,
        );
    }

    /**
     * The invoice customs reads, with one line per variant and price paid across every package: customs is told
     * what the shipment carries, not how it was packed.
     *
     * @param non-empty-list<ShipmentPackage> $packages
     *
     * @throws UnissuableShipmentException When there is nothing to declare
     */
    private function invoice(OrderInterface $order, array $packages): CustomsInvoice
    {
        /** @var array<string, CustomsItem> $lines */
        $lines = [];
        foreach ($packages as $package) {
            foreach ($package->customsItems as $item) {
                $key = sprintf('%s|%d', $item->code, $item->unitValue);
                $lines[$key] = isset($lines[$key])
                    ? new CustomsItem($item->hsCode, $item->countryOfOrigin, $item->description, $lines[$key]->quantity + $item->quantity, $item->unitValue, $item->currencyCode, $item->code)
                    : $item;
            }
        }

        if ([] === $lines) {
            throw new UnissuableShipmentException('The shipment crosses a border but carries nothing customs can be told about.');
        }

        return new CustomsInvoice(
            (string) ($order->getNumber() ?? $order->getId()),
            $this->clock->now(),
            (string) $order->getCurrencyCode(),
            array_values($lines),
        );
    }

    /**
     * @param string|null $currencyCode What the order was paid in, or null when nothing has to be declared
     *
     * @return non-empty-list<ShipmentPackage>
     *
     * @throws UnissuableShipmentException
     */
    private function packages(ShipmentInterface $shipment, ?string $currencyCode): array
    {
        $packaging = $this->packagingRepository->findOneBy(['shipment' => $shipment]);
        if (!$packaging instanceof CarrierShipmentPackagingInterface) {
            throw new UnissuableShipmentException('The packages of the shipment were never stored, so there is nothing to print a label for.');
        }

        if (CarrierShipmentPackagingInterface::STATE_PERSISTED !== $packaging->getState()) {
            throw new UnissuableShipmentException(sprintf(
                'The packages of the shipment could not be stored when its order was confirmed: %s',
                (string) $packaging->getFailureReason(),
            ));
        }

        $packages = [];
        foreach ($packaging->getPackages() as $stored) {
            $packages[] = new ShipmentPackage(
                self::package($stored),
                null === $currencyCode ? null : $this->declaredValueCalculator->forPackage($stored),
                $currencyCode,
                null === $currencyCode ? [] : $this->customsItems($stored, $currencyCode),
            );
        }

        if ([] === $packages) {
            throw new UnissuableShipmentException('The stored packaging of the shipment has no packages.');
        }

        return $packages;
    }

    /**
     * What customs is told this package holds. A variant that cannot be declared stops the shipment here,
     * before anything is asked of the carrier, and says which variant and what it is missing.
     *
     * @return list<CustomsItem>
     *
     * @throws UnissuableShipmentException
     */
    private function customsItems(CarrierShipmentPackageInterface $stored, string $currencyCode): array
    {
        try {
            return $this->customsDataProvider->forPackage($stored, $currencyCode);
        } catch (MissingCustomsDataException $exception) {
            throw new UnissuableShipmentException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @throws UnissuableShipmentException
     */
    private static function package(CarrierShipmentPackageInterface $stored): Package
    {
        $length = $stored->getLength();
        $width = $stored->getWidth();
        $height = $stored->getHeight();
        $dimensionUnit = $stored->getDimensionUnit();
        $weight = $stored->getWeight();
        $weightUnit = $stored->getWeightUnit();

        if (null === $length || null === $width || null === $height || null === $dimensionUnit || null === $weight || null === $weightUnit) {
            throw new UnissuableShipmentException(sprintf('The stored package %d of the shipment has no measures.', $stored->getPosition()));
        }

        return new Package(
            $stored->getBoxName(),
            $length,
            $width,
            $height,
            $dimensionUnit,
            $weight,
            $weightUnit,
            array_values($stored->getUnits()->toArray()),
        );
    }
}
