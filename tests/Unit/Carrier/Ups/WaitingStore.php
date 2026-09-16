<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Carrier\Ups;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * A lock store that lets another process finish its work the first time a lock is asked for, as if that
 * process had been holding the lock meanwhile.
 */
final class WaitingStore implements PersistingStoreInterface
{
    /** @var (\Closure(): mixed)|null */
    private ?\Closure $whileWaiting;

    /**
     * @param \Closure(): mixed $whileWaiting
     */
    public function __construct(
        private readonly PersistingStoreInterface $store,
        \Closure $whileWaiting,
    ) {
        $this->whileWaiting = $whileWaiting;
    }

    public function save(Key $key): void
    {
        if (null !== $this->whileWaiting) {
            $whileWaiting = $this->whileWaiting;
            $this->whileWaiting = null;
            $whileWaiting();
        }

        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}
