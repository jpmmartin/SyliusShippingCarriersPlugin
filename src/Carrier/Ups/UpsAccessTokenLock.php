<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

use ShipStream\Ups\Authentication\AccessTokenLock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Makes the processes that find the shared UPS access token expired renew it one at a time: the first one
 * renews it, and the others find the new one when they get the lock. Works with whatever store the
 * application configures for symfony/lock.
 */
final class UpsAccessTokenLock implements AccessTokenLock
{
    /** Long enough for a token request, short enough not to hold up a checkout if a process dies holding it. */
    private const TTL_SECONDS = 30.0;

    private ?LockInterface $lock = null;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly string $clientId,
    ) {
    }

    public function lock(): void
    {
        $this->lock = $this->lockFactory->createLock('jpmmartin_carrier.ups_access_token.' . hash('sha256', $this->clientId), self::TTL_SECONDS);
        $this->lock->acquire(true);
    }

    public function unlock(): void
    {
        $this->lock?->release();
        $this->lock = null;
    }
}
