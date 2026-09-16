<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'jpmmartin_carrier_credentials')]
// One set of credentials per carrier. Named explicitly so the mapping and the migration agree.
#[ORM\UniqueConstraint(name: 'uniq_jpmmartin_carrier_credentials_carrier', columns: ['carrier'])]
class CarrierCredentials implements CarrierCredentialsInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 16)]
    protected ?string $carrier = null;

    /**
     * No default on purpose, in PHP or in the database: whether the credentials go against the
     * sandbox or production is always an explicit choice (CA-7).
     */
    #[ORM\Column(type: 'string', length: 16)]
    protected ?string $environment = null;

    /**
     * Plain in memory, encrypted value by value in the database: EntityEncryptionListener encrypts
     * them on flush and decrypts them on load.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    protected array $credentials = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCarrier(): ?string
    {
        return $this->carrier;
    }

    public function setCarrier(?string $carrier): void
    {
        $this->carrier = $carrier;
    }

    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    public function setEnvironment(?string $environment): void
    {
        $this->environment = $environment;
    }

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    /**
     * Values left empty are not kept: an absent key means "not provided", so every stored value is a
     * non-empty string the encrypter can handle.
     */
    public function setCredentials(array $credentials): void
    {
        $this->credentials = array_filter(
            $credentials,
            static fn (?string $value): bool => null !== $value && '' !== $value,
        );
    }
}
