<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
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
     * @throws CarrierCredentialsException When none are stored for the carrier, they cannot be decrypted, or they lack
     *                                     the client id, the secret or the pickup type
     */
    public function get(string $carrier): CarrierCredentialsInterface
    {
        try {
            $credentials = $this->credentialsRepository->findOneBy(['carrier' => $carrier]);
        } catch (EncryptionException $exception) {
            // The key changed, is missing, or the database came from another installation. Whatever it is, the store
            // cannot read what it stored, which for the cart and the checkout is the same as having no credentials.
            throw self::undecryptable($carrier, $exception);
        }

        if (!$credentials instanceof CarrierCredentialsInterface) {
            throw new CarrierCredentialsException(sprintf('No credentials are stored for the carrier "%s".', $carrier));
        }

        $values = $credentials->getCredentials();

        // A load that failed to decrypt leaves the entity in memory with its values still encrypted, and Doctrine hands
        // that same one back to the next lookup of the request without decrypting it again. Sent on, the ciphertext
        // would reach the carrier as the client id.
        foreach ($values as $value) {
            if (str_ends_with($value, EncrypterInterface::ENCRYPTION_SUFFIX)) {
                throw self::undecryptable($carrier);
            }
        }

        if (!isset($values[CarrierCredentialsInterface::CLIENT_ID], $values[CarrierCredentialsInterface::CLIENT_SECRET])) {
            throw new CarrierCredentialsException(sprintf('The credentials of the carrier "%s" lack the client id or the client secret.', $carrier));
        }

        if (null === $credentials->getPickupType()) {
            throw new CarrierCredentialsException(sprintf('The credentials of the carrier "%s" lack how packages reach it.', $carrier));
        }

        return $credentials;
    }

    private static function undecryptable(string $carrier, ?\Throwable $previous = null): CarrierCredentialsException
    {
        return new CarrierCredentialsException(sprintf(
            'The credentials stored for the carrier "%s" cannot be decrypted with this store\'s encryption key: it is not '
            . 'the key they were saved with, or it is missing.',
            $carrier,
        ), 0, $previous);
    }
}
