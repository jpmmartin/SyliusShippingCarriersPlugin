<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

use JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException;
use Psr\Cache\CacheItemPoolInterface;
use Saloon\Http\Auth\AccessTokenAuthenticator;
use ShipStream\FedEx\Auth\TokenSerializer;
use ShipStream\FedEx\Contracts\TokenCache;

/**
 * Keeps the FedEx access token where every process finds it, encrypted, as for UPS (D-25).
 *
 * The SDK calls TokenCache statically, so the pool and the encrypter are bound to the class through
 * configure() rather than injected: FedexConnectorFactory binds them when it is built.
 */
final class FedexTokenCache implements TokenCache
{
    private static ?CacheItemPoolInterface $pool = null;

    private static ?EncrypterInterface $encrypter = null;

    public static function configure(CacheItemPoolInterface $pool, EncrypterInterface $encrypter): void
    {
        self::$pool = $pool;
        self::$encrypter = $encrypter;
    }

    /**
     * False when there is no usable token: none stored, expired, or unreadable with the current key.
     */
    public static function get(string $key): AccessTokenAuthenticator|false
    {
        if (null === self::$pool || null === self::$encrypter) {
            return false;
        }

        $encrypted = self::$pool->getItem(self::itemKey($key))->get();
        if (!\is_string($encrypted)) {
            return false;
        }

        try {
            $authenticator = TokenSerializer::unserialize(self::$encrypter->decrypt($encrypted));
        } catch (EncryptionException) {
            return false;
        }

        return null === $authenticator || $authenticator->hasExpired() ? false : $authenticator;
    }

    public static function set(string $key, AccessTokenAuthenticator $authenticator): void
    {
        if (null === self::$pool || null === self::$encrypter) {
            return;
        }

        $item = self::$pool->getItem(self::itemKey($key));
        $item->set(self::$encrypter->encrypt(TokenSerializer::serialize($authenticator)));
        // FedEx locks before generating a token it does not find, so the entry needs to live no longer than the token.
        $item->expiresAt($authenticator->getExpiresAt());

        self::$pool->save($item);
    }

    private static function itemKey(string $key): string
    {
        return 'fedex_access_token_' . hash('sha256', $key);
    }
}
