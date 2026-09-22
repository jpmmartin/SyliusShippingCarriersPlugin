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

    /**
     * The carrier is told what to answer when it is asked where a shipment is.
     *
     * @param list<array{occurred_at: string, description: string, location: string|null}> $events
     */
    public function trackShipment(string $carrier, ?string $status, array $events): void
    {
        $state = $this->read();
        $state[$carrier]['failure'] = null;
        $state[$carrier]['tracking'] = ['status' => $status, 'events' => $events];

        $this->write($state);
    }

    /**
     * @param array{occurred_at: string, description: string, location: string|null} $event
     */
    public function addTrackingEvent(string $carrier, array $event): void
    {
        $tracking = $this->tracking($carrier) ?? ['status' => null, 'events' => []];
        $tracking['events'][] = $event;

        $this->trackShipment($carrier, $tracking['status'], $tracking['events']);
    }

    /**
     * Null when the carrier was never told where a shipment is.
     *
     * @return array{status: string|null, events: list<array{occurred_at: string, description: string, location: string|null}>}|null
     */
    public function tracking(string $carrier): ?array
    {
        return $this->read()[$carrier]['tracking'] ?? null;
    }

    public function recordTrackCall(string $carrier): void
    {
        $state = $this->read();
        $state[$carrier]['track_calls'] = ($state[$carrier]['track_calls'] ?? 0) + 1;

        $this->write($state);
    }

    public function trackCalls(string $carrier): int
    {
        return $this->read()[$carrier]['track_calls'] ?? 0;
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
            unset($carrierState['calls'], $carrierState['residential_destination'], $carrierState['track_calls'], $carrierState['ship_calls'], $carrierState['voided']);
            $state[$carrier] = $carrierState;
        }

        $this->write($state);
    }

    /**
     * What the carrier answers when it is asked to ship: what it will call the shipment, and what it prints the
     * labels as. One label comes back per package of the request, which is the carrier's own rule.
     */
    public function issueLabelsAs(string $carrier, string $reference, string $format): void
    {
        $state = $this->read();
        $state[$carrier]['failure'] = null;
        $state[$carrier]['shipment'] = ['reference' => $reference, 'format' => $format];

        $this->write($state);
    }

    /**
     * @return array{reference: string, format: string}|null Null when no scenario said what it issues
     */
    public function shipment(string $carrier): ?array
    {
        return $this->read()[$carrier]['shipment'] ?? null;
    }

    public function recordShipCall(string $carrier): void
    {
        $state = $this->read();
        $state[$carrier]['ship_calls'] = ($state[$carrier]['ship_calls'] ?? 0) + 1;

        $this->write($state);
    }

    public function shipCalls(string $carrier): int
    {
        return $this->read()[$carrier]['ship_calls'] ?? 0;
    }

    /**
     * The carrier refuses to cancel, which leaves the labels issued and still being billed.
     */
    public function refuseToVoid(string $carrier, string $reason): void
    {
        $state = $this->read();
        $state[$carrier]['void_refusal'] = $reason;

        $this->write($state);
    }

    public function voidRefusal(string $carrier): ?string
    {
        return $this->read()[$carrier]['void_refusal'] ?? null;
    }

    public function recordVoid(string $carrier, string $reference): void
    {
        $state = $this->read();
        $voided = $state[$carrier]['voided'] ?? [];
        $voided[] = $reference;
        $state[$carrier]['voided'] = array_values($voided);

        $this->write($state);
    }

    /**
     * @return list<string> The shipments the carrier was asked to cancel, as it names them
     */
    public function voided(string $carrier): array
    {
        return $this->read()[$carrier]['voided'] ?? [];
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
     * @return array<string, array{failure?: self::FAILURE_*|null, rates?: array<string, array{amount: int, currency: string}>, calls?: int, residential_destination?: bool, track_calls?: int, tracking?: array{status: string|null, events: list<array{occurred_at: string, description: string, location: string|null}>}, shipment?: array{reference: string, format: string}, ship_calls?: int, voided?: list<string>, void_refusal?: string|null}>
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        /** @var array<string, array{failure?: self::FAILURE_*|null, rates?: array<string, array{amount: int, currency: string}>, calls?: int, residential_destination?: bool, track_calls?: int, tracking?: array{status: string|null, events: list<array{occurred_at: string, description: string, location: string|null}>}, shipment?: array{reference: string, format: string}, ship_calls?: int, voided?: list<string>, void_refusal?: string|null}> $state */
        $state = json_decode((string) file_get_contents($this->path), true, flags: \JSON_THROW_ON_ERROR);

        return $state;
    }

    /**
     * @param array<string, array{failure?: self::FAILURE_*|null, rates?: array<string, array{amount: int, currency: string}>, calls?: int, residential_destination?: bool, track_calls?: int, tracking?: array{status: string|null, events: list<array{occurred_at: string, description: string, location: string|null}>}, shipment?: array{reference: string, format: string}, ship_calls?: int, voided?: list<string>, void_refusal?: string|null}> $state
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
