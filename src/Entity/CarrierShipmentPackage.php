<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Shipping\Model\ShipmentUnitInterface;

#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_shipment_package')]
// Named explicitly so the mapping and the migration agree.
#[ORM\Index(name: 'idx_jpmmartin_carrier_package_packaging', columns: ['packaging_id'])]
class CarrierShipmentPackage implements CarrierShipmentPackageInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CarrierShipmentPackagingInterface::class, inversedBy: 'packages')]
    #[ORM\JoinColumn(name: 'packaging_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?CarrierShipmentPackagingInterface $packaging = null;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    #[ORM\Column(name: 'box_name', type: 'string', nullable: true)]
    protected ?string $boxName = null;

    #[ORM\Column(type: 'float')]
    protected ?float $length = null;

    #[ORM\Column(type: 'float')]
    protected ?float $width = null;

    #[ORM\Column(type: 'float')]
    protected ?float $height = null;

    #[ORM\Column(name: 'dimension_unit', type: 'string', length: 8)]
    protected ?string $dimensionUnit = null;

    #[ORM\Column(type: 'float')]
    protected ?float $weight = null;

    #[ORM\Column(name: 'weight_unit', type: 'string', length: 8)]
    protected ?string $weightUnit = null;

    /**
     * The Shipping component interface, the one registered as the `sylius.shipment_unit` resource: an order
     * item unit. A unit travels in a single package, hence the unique column.
     *
     * @var Collection<int, ShipmentUnitInterface>
     */
    #[ORM\ManyToMany(targetEntity: ShipmentUnitInterface::class)]
    #[ORM\JoinTable(name: 'jpmmartin_carrier_shipment_package_unit')]
    #[ORM\JoinColumn(name: 'package_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'unit_id', referencedColumnName: 'id', unique: true, onDelete: 'CASCADE')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    protected Collection $units;

    public function __construct()
    {
        $this->units = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPackaging(): ?CarrierShipmentPackagingInterface
    {
        return $this->packaging;
    }

    public function setPackaging(?CarrierShipmentPackagingInterface $packaging): void
    {
        $this->packaging = $packaging;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getBoxName(): ?string
    {
        return $this->boxName;
    }

    public function setBoxName(?string $boxName): void
    {
        $this->boxName = $boxName;
    }

    public function getLength(): ?float
    {
        return $this->length;
    }

    public function setLength(?float $length): void
    {
        $this->length = $length;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function setWidth(?float $width): void
    {
        $this->width = $width;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    public function setHeight(?float $height): void
    {
        $this->height = $height;
    }

    public function getDimensionUnit(): ?string
    {
        return $this->dimensionUnit;
    }

    public function setDimensionUnit(?string $dimensionUnit): void
    {
        $this->dimensionUnit = $dimensionUnit;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setWeight(?float $weight): void
    {
        $this->weight = $weight;
    }

    public function getWeightUnit(): ?string
    {
        return $this->weightUnit;
    }

    public function setWeightUnit(?string $weightUnit): void
    {
        $this->weightUnit = $weightUnit;
    }

    public function getUnits(): Collection
    {
        return $this->units;
    }

    public function addUnit(ShipmentUnitInterface $unit): void
    {
        if (!$this->units->contains($unit)) {
            $this->units->add($unit);
        }
    }
}
