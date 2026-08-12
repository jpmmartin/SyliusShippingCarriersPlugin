<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ChannelInterface;

#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_shipping_origin')]
// Named explicitly so the mapping and the migration agree. Left to Doctrine, the
// index gets a hash-based name that the migration cannot know, and every diff
// then proposes renaming it back and forth forever.
#[ORM\UniqueConstraint(name: 'uniq_jpmmartin_carrier_origin_channel', columns: ['channel_id'])]
class CarrierShippingOrigin implements CarrierShippingOriginInterface
{
    /**
     * IDENTITY, not AUTO. AUTO lets Doctrine pick the generation strategy per
     * platform, so the mapping would mean different things depending on where it
     * runs. IDENTITY means the same everywhere — the database assigns the value on
     * insert — which is what an autoincrement column is, and keeps this entity
     * independent of the engine behind it.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /**
     * The target entity is the Channel component interface, not the Core one:
     * that is the interface registered as the `sylius.channel` resource, and
     * therefore the one DoctrineTargetEntitiesResolverPass knows how to map.
     *
     * Unique on purpose: one origin per channel (CA-2).
     */
    #[ORM\ManyToOne(targetEntity: \Sylius\Component\Channel\Model\ChannelInterface::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?ChannelInterface $channel = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $street = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $city = null;

    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $postcode = null;

    #[ORM\Column(name: 'country_code', type: 'string', length: 2, nullable: true)]
    protected ?string $countryCode = null;

    #[ORM\Column(name: 'province_code', type: 'string', nullable: true)]
    protected ?string $provinceCode = null;

    #[ORM\Column(name: 'weight_unit', type: 'string', length: 8)]
    protected string $weightUnit = self::WEIGHT_UNIT_LB;

    #[ORM\Column(name: 'dimension_unit', type: 'string', length: 8)]
    protected string $dimensionUnit = self::DIMENSION_UNIT_IN;

    #[ORM\Column(name: 'max_package_weight', type: 'float')]
    protected float $maxPackageWeight = self::DEFAULT_MAX_PACKAGE_WEIGHT_LB;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): ?ChannelInterface
    {
        return $this->channel;
    }

    public function setChannel(?ChannelInterface $channel): void
    {
        $this->channel = $channel;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(?string $street): void
    {
        $this->street = $street;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    public function setPostcode(?string $postcode): void
    {
        $this->postcode = $postcode;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): void
    {
        $this->countryCode = $countryCode;
    }

    public function getProvinceCode(): ?string
    {
        return $this->provinceCode;
    }

    public function setProvinceCode(?string $provinceCode): void
    {
        $this->provinceCode = $provinceCode;
    }

    public function getWeightUnit(): string
    {
        return $this->weightUnit;
    }

    public function setWeightUnit(string $weightUnit): void
    {
        $this->weightUnit = $weightUnit;
    }

    public function getDimensionUnit(): string
    {
        return $this->dimensionUnit;
    }

    public function setDimensionUnit(string $dimensionUnit): void
    {
        $this->dimensionUnit = $dimensionUnit;
    }

    public function getMaxPackageWeight(): float
    {
        return $this->maxPackageWeight;
    }

    public function setMaxPackageWeight(float $maxPackageWeight): void
    {
        $this->maxPackageWeight = $maxPackageWeight;
    }
}
