<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use Psr\Cache\CacheItemPoolInterface;
use ShipStream\Ups\Authentication\AccessToken;
use ShipStream\Ups\Authentication\AccessTokenCache;

/**
 * Keeps the UPS access token where every process finds it, encrypted, since it gives access to the
 * merchant's account.
 *
 * @internal
 */
final readonly class UpsAccessTokenCache implements AccessTokenCache
{
    /**
     * The SDK only renews under its lock a token it finds expired in the cache, so the entry outlives the token.
     */
    private const LIFETIME_AFTER_EXPIRY = 86400;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private EncrypterInterface $encrypter,
    ) {
    }

    public function save(AccessToken $accessToken): void
    {
        $item = $this->cache->getItem($this->key($accessToken->getClientId()));
        $item->set($this->encrypter->encrypt(serialize($accessToken)));
        $item->expiresAfter(max(0, $accessToken->getExpiresIn()) + self::LIFETIME_AFTER_EXPIRY);

        $this->cache->save($item);
    }

    public function retrieve(string $clientId): ?AccessToken
    {
        $item = $this->cache->getItem($this->key($clientId));
        $encrypted = $item->get();
        if (!$item->isHit() || !\is_string($encrypted)) {
            return null;
        }

        try {
            $accessToken = unserialize($this->encrypter->decrypt($encrypted), ['allowed_classes' => [AccessToken::class]]);
        } catch (EncryptionException) {
            // Encrypted with a key that is no longer the current one: UPS is simply asked for a new token.
            return null;
        }

        return $accessToken instanceof AccessToken ? $accessToken : null;
    }

    private function key(string $clientId): string
    {
        return 'ups_access_token_' . hash('sha256', $clientId);
    }
}
