<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * The customs data of a product variant, kept in a table of the plugin.
 *
 * It is not written on `ProductVariant` on purpose: overriding a Sylius model is exclusive, so a plugin that
 * did it would break with any other plugin that also did.
 */
#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_customs_data')]
// Named explicitly so the mapping and the migration agree (see CarrierShippingOrigin).
#[ORM\UniqueConstraint(name: 'uniq_jpmmartin_carrier_customs_variant', columns: ['variant_id'])]
class CarrierCustomsData implements CarrierCustomsDataInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /**
     * The target entity is the Product component interface, not the Core one: that is the one registered as
     * the `sylius.product_variant` resource, and therefore the one DoctrineTargetEntitiesResolverPass knows
     * how to map.
     *
     * Unique on purpose: one set of customs data per variant.
     */
    #[ORM\ManyToOne(targetEntity: \Sylius\Component\Product\Model\ProductVariantInterface::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?ProductVariantInterface $variant = null;

    /**
     * Six to ten digits. Kept without separators, so two catalogues written with different punctuation still
     * declare the same code.
     */
    #[ORM\Column(name: 'hs_code', type: 'string', length: 16, nullable: true)]
    protected ?string $hsCode = null;

    #[ORM\Column(name: 'country_of_origin', type: 'string', length: 2, nullable: true)]
    protected ?string $countryOfOrigin = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): ?ProductVariantInterface
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariantInterface $variant): void
    {
        $this->variant = $variant;
    }

    public function getHsCode(): ?string
    {
        return $this->hsCode;
    }

    public function setHsCode(?string $hsCode): void
    {
        $this->hsCode = $hsCode;
    }

    public function getCountryOfOrigin(): ?string
    {
        return $this->countryOfOrigin;
    }

    public function setCountryOfOrigin(?string $countryOfOrigin): void
    {
        $this->countryOfOrigin = $countryOfOrigin;
    }

    public function isComplete(): bool
    {
        return null !== $this->hsCode && '' !== $this->hsCode &&
            null !== $this->countryOfOrigin && '' !== $this->countryOfOrigin;
    }
}
