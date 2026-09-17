<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Behat\Carrier;

/**
 * How the fake carriers answer and how often they were asked, kept in a file.
 *
 * Behat configures the store in one kernel and sends requests to another, and each request resets the services, so
 * nothing kept in memory would reach the carrier the shop calls.
 */
final class FakeCarrierState
{
    /** The carrier does not answer in time. */
    public const FAILURE_TIMEOUT = 'timeout';

    /** The carrier answers with a server error. */
    public const FAILURE_SERVER_ERROR = 'server_error';

    /** The carrier answers with something that cannot be read. */
    public const FAILURE_UNREADABLE = 'unreadable';

    /** The carrier rejects the credentials. */
    public const FAILURE_CREDENTIALS = 'credentials';

    public function __construct(
        private readonly string $path,
    ) {
    }

    public function reset(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function rateService(string $carrier, string $serviceCode, int $amount, string $currencyCode): void
    {
        $state = $this->read();
        $state[$carrier]['failure'] = null;
        $state[$carrier]['rates'][$serviceCode] = ['amount' => $amount, 'currency' => $currencyCode];

        $this->write($state);
    }

    /**
     * @param self::FAILURE_* $failure
     */
    public function fail(string $carrier, string $failure): void
    {
        $state = $this->read();
        $state[$carrier]['failure'] = $failure;

        $this->write($state);
    }

    public function recordCall(string $carrier, bool $residentialDestination): void
    {
        $state = $this->read();
        $state[$carrier]['calls'] = ($state[$carrier]['calls'] ?? 0) + 1;
        $state[$carrier]['residential_destination'] = $residentialDestination;

        $this->write($state);
    }

    /**
     * Whether the last rates asked of the carrier were for a home. Null when it was never asked.
     */
    public function askedForAResidentialDestination(string $carrier): ?bool
    {
        return $this->read()[$carrier]['residential_destination'] ?? null;
    }

    public function forgetCalls(): void
    {
        $state = $this->read();
        foreach ($state as $carrier => $carrierState) {
            unset($carrierState['calls'], $carrierState['residential_destination']);
            $state[$carrier] = $carrierState;
        }

        $this->write($state);
    }

    public function calls(string $carrier): int
    {
        return $this->read()[$carrier]['calls'] ?? 0;
    }

    /**
     * Null when the carrier was told to answer, with no rate for any service yet.
     *
     * @return self::FAILURE_*|null
     */
    public function failure(string $carrier): ?string
    {
        $state = $this->read()[$carrier] ?? null;
        if (null === $state) {
            return self::FAILURE_TIMEOUT;
        }

        return $state['failure'] ?? null;
    }

    /**
     * @return array<string, array{amount: int, currency: string}> By service code
     */
    public function rates(string $carrier): array
    {
        return $this->read()[$carrier]['rates'] ?? [];
    }

    /**
     * @return array<string, array{failure?: self::FAILURE_*|null, rates?: array<string, array{amount: int, currency: string}>, calls?: int, residential_destination?: bool}>
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        /** @var array<string, array{failure?: self::FAILURE_*|null, rates?: array<string, array{amount: int, currency: string}>, calls?: int, residential_destination?: bool}> $state */
        $state = json_decode((string) file_get_contents($this->path), true, flags: \JSON_THROW_ON_ERROR);

        return $state;
    }

    /**
     * @param array<string, array{failure?: self::FAILURE_*|null, rates?: array<string, array{amount: int, currency: string}>, calls?: int, residential_destination?: bool}> $state
     */
    private function write(array $state): void
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path, json_encode($state, \JSON_THROW_ON_ERROR), \LOCK_EX);
    }
}
