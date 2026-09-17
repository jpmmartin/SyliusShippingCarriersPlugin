<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * The credentials an adapter calls its carrier with, as the administrator stored them.
 */
final readonly class CredentialsProvider
{
    /**
     * @param RepositoryInterface<CarrierCredentialsInterface> $credentialsRepository
     */
    public function __construct(
        private RepositoryInterface $credentialsRepository,
    ) {
    }

    /**
     * @throws CarrierCredentialsException When none are stored for the carrier, or they lack the client id, the secret or
     *                                     the pickup type
     */
    public function get(string $carrier): CarrierCredentialsInterface
    {
        $credentials = $this->credentialsRepository->findOneBy(['carrier' => $carrier]);
        if (!$credentials instanceof CarrierCredentialsInterface) {
            throw new CarrierCredentialsException(sprintf('No credentials are stored for the carrier "%s".', $carrier));
        }

        $values = $credentials->getCredentials();
        if (!isset($values[CarrierCredentialsInterface::CLIENT_ID], $values[CarrierCredentialsInterface::CLIENT_SECRET])) {
            throw new CarrierCredentialsException(sprintf('The credentials of the carrier "%s" lack the client id or the client secret.', $carrier));
        }

        if (null === $credentials->getPickupType()) {
            throw new CarrierCredentialsException(sprintf('The credentials of the carrier "%s" lack how packages reach it.', $carrier));
        }

        return $credentials;
    }
}
