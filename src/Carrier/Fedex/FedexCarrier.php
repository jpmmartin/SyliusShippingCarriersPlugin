<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\MinorUnits;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingEvent;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\AccountNumber;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\Address as FedexAddress;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\Dimensions;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\FullSchemaQuoteRate;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\RatedShipmentDetail;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\RateParty;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\RateReplyDetail;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\RequestedPackageLineItem;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\RequestedShipment;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Dto\Weight;
use ShipStream\FedEx\Api\RatesAndTransitTimesV1\Responses\RatcResponseVo;
use ShipStream\FedEx\Api\TrackV1\Dto\FullSchemaTrackingNumbers;
use ShipStream\FedEx\Api\TrackV1\Dto\ScanEvent;
use ShipStream\FedEx\Api\TrackV1\Dto\TrackingInfo as FedexTrackingInfo;
use ShipStream\FedEx\Api\TrackV1\Dto\TrackingNumberInfo;
use ShipStream\FedEx\Api\TrackV1\Dto\TrackResult;
use ShipStream\FedEx\Api\TrackV1\Responses\TrkcResponseVoTrackingNumber;

/**
 * FedEx through shipstream/fedex-rest-sdk. Every exception of the SDK, of Saloon or of the JSON decoding is
 * translated here to a CarrierException.
 *
 * @internal
 */
final class FedexCarrier implements CarrierInterface
{
    /** The rates of the merchant's account, what FedEx bills. */
    private const RATE_TYPE_ACCOUNT = 'ACCOUNT';

    /** What is being asked of FedEx, for the message when it refuses. */
    private const RATE_OPERATION = 'the rate request';

    private const TRACKING_OPERATION = 'the tracking request';

    /** Packaging of the shipper's own, not a FedEx box. */
    private const PACKAGING_TYPE_YOUR_PACKAGING = 'YOUR_PACKAGING';

    /** @var array<string, string> FedEx pickup types */
    private const PICKUP_TYPES = [
        CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED => 'USE_SCHEDULED_PICKUP',
        CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF => 'DROPOFF_AT_FEDEX_LOCATION',
        CarrierCredentialsInterface::PICKUP_TYPE_ON_DEMAND => 'CONTACT_FEDEX_TO_SCHEDULE',
    ];

    public function __construct(
        private readonly CredentialsProvider $credentialsProvider,
        private readonly FedexConnectorFactory $connectorFactory,
        private readonly FedexErrorTranslator $errorTranslator = new FedexErrorTranslator(),
    ) {
    }

    public function rate(RateRequest $request): RateSet
    {
        try {
            $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_FEDEX);
            $accountNumber = $credentials->getCredentials()[CarrierCredentialsInterface::ACCOUNT_NUMBER]
                ?? throw new CarrierCredentialsException('FedEx does not quote without an account number.');
            $pickupType = self::PICKUP_TYPES[(string) $credentials->getPickupType()]
                ?? throw new CarrierCredentialsException(sprintf('"%s" is not a pickup type FedEx knows.', (string) $credentials->getPickupType()));

            $response = $this->connectorFactory->create($credentials)->ratesTransitTimesV1()->rateAndTransitTimes(new FullSchemaQuoteRate(
                accountNumber: new AccountNumber($accountNumber),
                requestedShipment: new RequestedShipment(
                    shipper: new RateParty($this->address($request->origin)),
                    recipient: new RateParty($this->address($request->destination, $request->destination->residential)),
                    pickupType: $pickupType,
                    requestedPackageLineItems: array_map($this->package(...), $request->packages),
                    rateRequestType: [self::RATE_TYPE_ACCOUNT],
                    packagingType: self::PACKAGING_TYPE_YOUR_PACKAGING,
                ),
            ));

            $quote = $response->dto();
            if (!$quote instanceof RatcResponseVo) {
                throw new UnexpectedCarrierResponseException('FedEx answered the rate request without a rate reply.');
            }

            return $this->readRates($quote);
        } catch (\Throwable $exception) {
            throw $this->errorTranslator->translate($exception, self::RATE_OPERATION);
        }
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        try {
            $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_FEDEX);

            $response = $this->connectorFactory->create($credentials)->trackV1()->trackByTrackingNumber(new FullSchemaTrackingNumbers(
                includeDetailedScans: true,
                trackingInfo: [new FedexTrackingInfo(new TrackingNumberInfo($trackingNumber))],
            ));

            $tracking = $response->dto();
            if (!$tracking instanceof TrkcResponseVoTrackingNumber) {
                throw new UnexpectedCarrierResponseException('FedEx answered the tracking enquiry without a tracking response.');
            }

            return $this->readTracking($trackingNumber, $tracking);
        } catch (\Throwable $exception) {
            throw $this->errorTranslator->translate($exception, self::TRACKING_OPERATION);
        }
    }

    /**
     * FedEx answers with one result per tracking number, and the newest scan is the last of the list.
     */
    private function readTracking(string $trackingNumber, TrkcResponseVoTrackingNumber $tracking): TrackingInfo
    {
        $result = ($tracking->output?->completeTrackResults[0] ?? null)?->trackResults[0] ?? null;
        if (!$result instanceof TrackResult) {
            throw new UnexpectedCarrierResponseException(sprintf('FedEx knows no shipment for the tracking number "%s".', $trackingNumber));
        }

        $events = [];
        foreach ($result->scanEvents ?? [] as $scan) {
            if (!$scan instanceof ScanEvent) {
                continue;
            }

            $description = (string) ($scan->eventDescription ?? $scan->derivedStatus);
            if ('' === $description) {
                continue;
            }

            $events[] = new TrackingEvent($this->scannedAt($scan), $description, $this->place($scan));
        }

        $status = $result->latestStatusDetail?->statusByLocale ?? $result->latestStatusDetail?->description;

        return new TrackingInfo($trackingNumber, '' === $status ? null : $status, $events);
    }

    private function scannedAt(ScanEvent $scan): ?\DateTimeImmutable
    {
        if (null === $scan->date) {
            return null;
        }

        try {
            return new \DateTimeImmutable($scan->date);
        } catch (\Exception) {
            return null;
        }
    }

    private function place(ScanEvent $scan): ?string
    {
        $place = array_filter([
            $scan->scanLocation?->city,
            $scan->scanLocation?->stateOrProvinceCode,
            $scan->scanLocation?->countryCode,
        ]);

        return [] === $place ? null : implode(', ', $place);
    }

    /**
     * FedEx rates without the street.
     *
     * @param bool|null $residential Only for the recipient, whether it is a home or a business
     */
    private function address(Address $address, ?bool $residential = null): FedexAddress
    {
        return new FedexAddress(
            city: $address->city,
            stateOrProvinceCode: $address->provinceCode,
            postalCode: $address->postcode,
            countryCode: $address->countryCode,
            residential: $residential,
        );
    }

    private function package(Package $package): RequestedPackageLineItem
    {
        $sides = [$package->length, $package->width, $package->height];
        rsort($sides);

        return new RequestedPackageLineItem(
            weight: new Weight(
                units: CarrierShippingOriginInterface::WEIGHT_UNIT_KG === $package->weightUnit ? 'KG' : 'LB',
                value: $this->roundUp($package->weight, 1),
            ),
            dimensions: new Dimensions(
                // FedEx takes whole measures; rounding up keeps the declared package from being smaller.
                length: (int) $this->roundUp($sides[0], 0),
                width: (int) $this->roundUp($sides[1], 0),
                height: (int) $this->roundUp($sides[2], 0),
                units: CarrierShippingOriginInterface::DIMENSION_UNIT_CM === $package->dimensionUnit ? 'CM' : 'IN',
            ),
        );
    }

    private function roundUp(float $value, int $decimals): float
    {
        $factor = 10 ** $decimals;

        // Rounding first drops the float noise that would otherwise push 12.000000000000002 up to 13.
        return ceil(round($value * $factor, 6)) / $factor;
    }

    private function readRates(RatcResponseVo $quote): RateSet
    {
        $details = $quote->output?->rateReplyDetails;
        if (null === $details || [] === $details) {
            throw new UnexpectedCarrierResponseException('FedEx answered the rate request without any rated service.');
        }

        $rates = [];
        foreach ($details as $detail) {
            if (!$detail instanceof RateReplyDetail || null === $detail->serviceType) {
                throw new UnexpectedCarrierResponseException('FedEx answered with a rated service without its type.');
            }

            $rated = $this->accountRate($detail);
            $amount = $rated?->totalNetCharge;
            $currencyCode = $rated?->shipmentRateDetail?->currency;
            if (null === $amount || null === $currencyCode) {
                throw new UnexpectedCarrierResponseException(sprintf('FedEx answered without the charge of the service "%s".', $detail->serviceType));
            }

            $rates[] = new Rate(
                $detail->serviceType,
                MinorUnits::fromDecimal(number_format($amount, 6, '.', '')),
                $currencyCode,
            );
        }

        return new RateSet($rates);
    }

    /**
     * The account rate when FedEx gives one, and otherwise the first it gives.
     */
    private function accountRate(RateReplyDetail $detail): ?RatedShipmentDetail
    {
        $rated = array_values(array_filter($detail->ratedShipmentDetails ?? [], static fn (mixed $rated): bool => $rated instanceof RatedShipmentDetail));

        foreach ($rated as $candidate) {
            if (self::RATE_TYPE_ACCOUNT === $candidate->rateType) {
                return $candidate;
            }
        }

        return $rated[0] ?? null;
    }
}
