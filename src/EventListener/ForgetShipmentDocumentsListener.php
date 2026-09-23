<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\EventListener;

use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use JpmMartin\SyliusShippingCarriersPlugin\Label\LabelStorage;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;

/**
 * Takes the labels and the customs document of a shipment that is being deleted.
 *
 * It listens to the shipment and not to the export because the export is removed by the database: its row has
 * `ON DELETE CASCADE`, so it goes without Doctrine ever hearing about it, and a listener on the export would
 * never run.
 *
 * Nothing has to be looked up to know what to take: everything kept for a shipment hangs from its own
 * directory. The id is noted while the deletion is being written and the files go once it is committed, so a
 * transaction that rolls back leaves the shipment with its files, not without them.
 *
 * @internal
 */
final class ForgetShipmentDocumentsListener
{
    /** @var list<int|string> Shipments whose deletion is being written right now. */
    private array $deleted = [];

    public function __construct(
        private readonly LabelStorage $labelStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof ShipmentInterface || null === $entity->getId()) {
                continue;
            }

            $this->deleted[] = $entity->getId();
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $deleted = $this->deleted;
        $this->deleted = [];

        foreach ($deleted as $shipmentId) {
            try {
                $this->labelStorage->forgetShipment($shipmentId);
            } catch (FilesystemException $exception) {
                // The row is gone and the file is not, which is what the purge collects.
                $this->logger->error('The documents of the deleted shipment {shipment} could not be deleted.', [
                    'shipment' => $shipmentId,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
