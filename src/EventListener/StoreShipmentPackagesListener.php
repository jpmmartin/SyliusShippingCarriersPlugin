<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\EventListener;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Workflow\Event\Event;

/**
 * Stores the packages of every shipment rated by a carrier when the order is confirmed, in the same save that
 * confirms it. From then on they are the only word on what that shipment physically is: nothing recalculates them.
 *
 * A shipment that cannot be packed is stored as a failure with its reason, and the order is confirmed all the same.
 *
 * @internal
 */
final readonly class StoreShipmentPackagesListener
{
    /**
     * @param RepositoryInterface<CarrierShippingOriginInterface> $originRepository
     * @param FactoryInterface<CarrierShipmentPackagingInterface> $packagingFactory
     * @param FactoryInterface<CarrierShipmentPackageInterface> $packageFactory
     */
    public function __construct(
        private ServiceRegistryInterface $calculators,
        private PackagingStrategyInterface $packagingStrategy,
        private RepositoryInterface $originRepository,
        private FactoryInterface $packagingFactory,
        private FactoryInterface $packageFactory,
        private ObjectManager $packagingManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Event $event): void
    {
        $order = $event->getSubject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        foreach ($order->getShipments() as $shipment) {
            if (!$this->isRatedByACarrier($shipment)) {
                continue;
            }

            $packaging = $this->packagingFactory->createNew();
            $packaging->setShipment($shipment);

            try {
                foreach ($this->pack($shipment, $order) as $position => $package) {
                    $packaging->addPackage($this->package($package, $position));
                }
            } catch (\Throwable $failure) {
                // The order is confirmed even so, with the reason kept: a shipment with no packages and no reason
                // would look like a shipment with nothing in it.
                $packaging->fail($failure->getMessage());
                $this->logger->error('The packages of a shipment could not be stored when its order was confirmed: {reason}', [
                    'reason' => $failure->getMessage(),
                    'order_id' => $order->getId(),
                ]);
            }

            $this->packagingManager->persist($packaging);
        }
    }

    /**
     * @return non-empty-list<Package>
     */
    private function pack(ShipmentInterface $shipment, OrderInterface $order): array
    {
        $channel = $order->getChannel();
        $origin = null === $channel ? null : $this->originRepository->findOneBy(['channel' => $channel]);
        if (!$origin instanceof CarrierShippingOriginInterface) {
            throw new \RuntimeException(sprintf('The channel "%s" has no shipping origin to pack from.', (string) $channel?->getCode()));
        }

        return $this->packagingStrategy->pack($shipment, $origin);
    }

    private function package(Package $package, int $position): CarrierShipmentPackageInterface
    {
        $stored = $this->packageFactory->createNew();
        $stored->setPosition($position);
        $stored->setBoxName($package->boxName);
        $stored->setLength($package->length);
        $stored->setWidth($package->width);
        $stored->setHeight($package->height);
        $stored->setDimensionUnit($package->dimensionUnit);
        $stored->setWeight($package->weight);
        $stored->setWeightUnit($package->weightUnit);

        foreach ($package->units as $unit) {
            $stored->addUnit($unit);
        }

        return $stored;
    }

    private function isRatedByACarrier(ShipmentInterface $shipment): bool
    {
        $calculatorName = $shipment->getMethod()?->getCalculator();

        return null !== $calculatorName &&
            $this->calculators->has($calculatorName) &&
            $this->calculators->get($calculatorName) instanceof CarrierRateCalculator;
    }
}
