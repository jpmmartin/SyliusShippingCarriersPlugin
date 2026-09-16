<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\MinorUnits;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate;
use JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Exceptions\Request\ServerException;
use Saloon\Exceptions\Request\Statuses\ForbiddenException;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Exceptions\Request\Statuses\TooManyRequestsException;
use Saloon\Exceptions\Request\Statuses\UnauthorizedException;
use Saloon\RateLimitPlugin\Exceptions\RateLimitReachedException;
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

/**
 * FedEx through shipstream/fedex-rest-sdk. Every exception of the SDK, of Saloon or of the JSON decoding is
 * translated here to a CarrierException (CA-23).
 */
final class FedexCarrier implements CarrierInterface
{
    /** The rates of the merchant's account, what FedEx bills (D-23). */
    private const RATE_TYPE_ACCOUNT = 'ACCOUNT';

    /** Packaging of the shipper's own, not a FedEx box. */
    private const PACKAGING_TYPE_YOUR_PACKAGING = 'YOUR_PACKAGING';

    /** @var array<string, string> FedEx pickup types (D-26) */
    private const PICKUP_TYPES = [
        CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED => 'USE_SCHEDULED_PICKUP',
        CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF => 'DROPOFF_AT_FEDEX_LOCATION',
        CarrierCredentialsInterface::PICKUP_TYPE_ON_DEMAND => 'CONTACT_FEDEX_TO_SCHEDULE',
    ];

    public function __construct(
        private readonly CredentialsProvider $credentialsProvider,
        private readonly FedexConnectorFactory $connectorFactory,
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
            throw $this->translate($exception);
        }
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        // The FedEx tracking adapter is a task of its own, after the rate providers.
        throw new CarrierUnavailableException('Tracking with FedEx is not implemented yet.');
    }

    /**
     * FedEx rates without the street.
     *
     * @param bool|null $residential Only for the recipient, whether it is a home or a business (D-28)
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
                // FedEx takes whole measures; rounding up keeps the declared package from being smaller (D-24).
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
                MinorUnits::fromDecimal(number_format($amount, 6, '.', ''), $currencyCode),
                $currencyCode,
            );
        }

        return new RateSet($rates);
    }

    /**
     * The account rate when FedEx gives one, and otherwise the first it gives (D-23).
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

    private function translate(\Throwable $exception): CarrierException
    {
        return match (true) {
            $exception instanceof CarrierException => $exception,
            $exception instanceof UnauthorizedException,
            $exception instanceof ForbiddenException => new CarrierCredentialsException(sprintf('FedEx rejected the credentials: %s', $this->errors($exception)), 0, $exception),
            // The SDK itself holds back token requests past FedEx's published limits, before sending them.
            $exception instanceof RateLimitReachedException => new CarrierUnavailableException(sprintf('FedEx refused the request: %s', $exception->getMessage()), 0, $exception),
            $exception instanceof TooManyRequestsException,
            $exception instanceof RequestTimeOutException,
            $exception instanceof ServerException => new CarrierUnavailableException(sprintf('FedEx answered with HTTP %d: %s', $exception->getStatus(), $this->errors($exception)), 0, $exception),
            $exception instanceof RequestException => new CarrierRejectedRequestException(sprintf('FedEx rejected the rate request: %s', $this->errors($exception)), 0, $exception),
            $exception instanceof FatalRequestException => new CarrierUnavailableException(sprintf('FedEx could not be reached: %s', $exception->getMessage()), 0, $exception),
            default => new UnexpectedCarrierResponseException(sprintf('FedEx answered with something the plugin cannot read: %s', $exception->getMessage()), 0, $exception),
        };
    }

    private function errors(RequestException $exception): string
    {
        try {
            $errors = $exception->getResponse()->json('errors');
        } catch (\Throwable) {
            return $exception->getMessage();
        }

        if (!\is_array($errors) || [] === $errors) {
            return $exception->getMessage();
        }

        return implode(' - ', array_map(
            static fn (mixed $error): string => \is_array($error)
                ? sprintf('%s: %s', \is_string($error['code'] ?? null) ? $error['code'] : '', \is_string($error['message'] ?? null) ? $error['message'] : '')
                : '',
            $errors,
        ));
    }
}
