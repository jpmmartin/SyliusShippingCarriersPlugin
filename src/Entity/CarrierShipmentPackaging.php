<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Shipping\Model\ShipmentInterface;

#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_shipment_packaging')]
// One packaging per shipment. Named explicitly so the mapping and the migration agree.
#[ORM\UniqueConstraint(name: 'uniq_jpmmartin_carrier_packaging_shipment', columns: ['shipment_id'])]
class CarrierShipmentPackaging implements CarrierShipmentPackagingInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /**
     * The Shipping component interface, the one registered as the `sylius.shipment` resource.
     */
    #[ORM\ManyToOne(targetEntity: ShipmentInterface::class)]
    #[ORM\JoinColumn(name: 'shipment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?ShipmentInterface $shipment = null;

    #[ORM\Column(type: 'string', length: 16)]
    protected string $state = self::STATE_PERSISTED;

    #[ORM\Column(name: 'failure_reason', type: 'text', nullable: true)]
    protected ?string $failureReason = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected \DateTimeImmutable $createdAt;

    /** @var Collection<int, CarrierShipmentPackageInterface> */
    #[ORM\OneToMany(targetEntity: CarrierShipmentPackageInterface::class, mappedBy: 'packaging', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    protected Collection $packages;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->packages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShipment(): ?ShipmentInterface
    {
        return $this->shipment;
    }

    public function setShipment(?ShipmentInterface $shipment): void
    {
        $this->shipment = $shipment;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function fail(string $reason): void
    {
        $this->state = self::STATE_FAILED;
        $this->failureReason = $reason;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPackages(): Collection
    {
        return $this->packages;
    }

    public function addPackage(CarrierShipmentPackageInterface $package): void
    {
        $package->setPosition($this->packages->count());
        $package->setPackaging($this);
        $this->packages->add($package);
    }
}
