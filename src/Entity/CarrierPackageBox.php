<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_package_box')]
class CarrierPackageBox implements CarrierPackageBoxInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string')]
    protected ?string $name = null;

    #[ORM\Column(name: 'inner_length', type: 'float')]
    protected ?float $innerLength = null;

    #[ORM\Column(name: 'inner_width', type: 'float')]
    protected ?float $innerWidth = null;

    #[ORM\Column(name: 'inner_height', type: 'float')]
    protected ?float $innerHeight = null;

    #[ORM\Column(name: 'outer_length', type: 'float')]
    protected ?float $outerLength = null;

    #[ORM\Column(name: 'outer_width', type: 'float')]
    protected ?float $outerWidth = null;

    #[ORM\Column(name: 'outer_height', type: 'float')]
    protected ?float $outerHeight = null;

    #[ORM\Column(name: 'empty_weight', type: 'float')]
    protected ?float $emptyWeight = null;

    #[ORM\Column(name: 'max_weight', type: 'float')]
    protected ?float $maxWeight = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getInnerLength(): ?float
    {
        return $this->innerLength;
    }

    public function setInnerLength(?float $innerLength): void
    {
        $this->innerLength = $innerLength;
    }

    public function getInnerWidth(): ?float
    {
        return $this->innerWidth;
    }

    public function setInnerWidth(?float $innerWidth): void
    {
        $this->innerWidth = $innerWidth;
    }

    public function getInnerHeight(): ?float
    {
        return $this->innerHeight;
    }

    public function setInnerHeight(?float $innerHeight): void
    {
        $this->innerHeight = $innerHeight;
    }

    public function getOuterLength(): ?float
    {
        return $this->outerLength;
    }

    public function setOuterLength(?float $outerLength): void
    {
        $this->outerLength = $outerLength;
    }

    public function getOuterWidth(): ?float
    {
        return $this->outerWidth;
    }

    public function setOuterWidth(?float $outerWidth): void
    {
        $this->outerWidth = $outerWidth;
    }

    public function getOuterHeight(): ?float
    {
        return $this->outerHeight;
    }

    public function setOuterHeight(?float $outerHeight): void
    {
        $this->outerHeight = $outerHeight;
    }

    public function getEmptyWeight(): ?float
    {
        return $this->emptyWeight;
    }

    public function setEmptyWeight(?float $emptyWeight): void
    {
        $this->emptyWeight = $emptyWeight;
    }

    public function getMaxWeight(): ?float
    {
        return $this->maxWeight;
    }

    public function setMaxWeight(?float $maxWeight): void
    {
        $this->maxWeight = $maxWeight;
    }
}
