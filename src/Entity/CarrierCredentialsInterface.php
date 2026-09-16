<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Entity;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncryptionAwareInterface;
use Sylius\Resource\Model\ResourceInterface;

interface CarrierCredentialsInterface extends ResourceInterface, EncryptionAwareInterface
{
    public const CARRIER_UPS = 'ups';

    public const CARRIER_FEDEX = 'fedex';

    public const ENVIRONMENT_SANDBOX = 'sandbox';

    public const ENVIRONMENT_PRODUCTION = 'production';

    /** How the merchant hands the packages to the carrier (D-26). */
    public const PICKUP_TYPE_SCHEDULED = 'scheduled';

    public const PICKUP_TYPE_DROP_OFF = 'drop_off';

    public const PICKUP_TYPE_ON_DEMAND = 'on_demand';

    /** The keys of getCredentials() (D-13). */
    public const CLIENT_ID = 'client_id';

    public const CLIENT_SECRET = 'client_secret';

    /** Required for FedEx; for UPS, it is what brings the negotiated rates (D-23). */
    public const ACCOUNT_NUMBER = 'account_number';

    public function getCarrier(): ?string;

    public function setCarrier(?string $carrier): void;

    public function getEnvironment(): ?string;

    public function setEnvironment(?string $environment): void;

    /** Changes the price, so the rates are asked for with it (CA-46). */
    public function getPickupType(): ?string;

    public function setPickupType(?string $pickupType): void;

    /** @return array<string, string> */
    public function getCredentials(): array;

    /**
     * Empty values are dropped: a form submits an optional field left empty as null.
     *
     * @param array<string, string|null> $credentials
     */
    public function setCredentials(array $credentials): void;
}
