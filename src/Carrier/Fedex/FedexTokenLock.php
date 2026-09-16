<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

use ShipStream\FedEx\Contracts\TokenLock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Makes the processes that find no FedEx access token get one at a time: the first one requests it, and the
 * others find it in the cache once they get the lock (D-25).
 */
final class FedexTokenLock implements TokenLock
{
    /** Long enough for a token request, short enough not to hold up a checkout if a process dies holding it. */
    private const TTL_SECONDS = 30.0;

    /** @var array<string, LockInterface> */
    private array $locks = [];

    public function __construct(
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function lock(string $key): void
    {
        $lock = $this->lockFactory->createLock('jpmmartin_carrier.fedex_access_token.' . hash('sha256', $key), self::TTL_SECONDS);
        $lock->acquire(true);

        $this->locks[$key] = $lock;
    }

    public function unlock(string $key): void
    {
        if (isset($this->locks[$key])) {
            $this->locks[$key]->release();
            unset($this->locks[$key]);
        }
    }
}
