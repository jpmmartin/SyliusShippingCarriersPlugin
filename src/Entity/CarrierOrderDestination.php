<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Order\Model\OrderInterface;

#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_order_destination')]
// One choice per order. Named explicitly so the mapping and the migration agree.
#[ORM\UniqueConstraint(name: 'uniq_jpmmartin_carrier_destination_order', columns: ['order_id'])]
class CarrierOrderDestination implements CarrierOrderDestinationInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /**
     * The Order component interface, the one registered as the `sylius.order` resource.
     */
    #[ORM\ManyToOne(targetEntity: OrderInterface::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected ?OrderInterface $order = null;

    #[ORM\Column(type: 'string', length: 16)]
    protected ?string $type = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function setOrder(?OrderInterface $order): void
    {
        $this->order = $order;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }
}
