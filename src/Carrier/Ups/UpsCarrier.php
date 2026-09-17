<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

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
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingEvent;
use JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo;
use Psr\Http\Client\ClientExceptionInterface;
use ShipStream\Ups\Api\Exception\GenerateTokenBadRequestException;
use ShipStream\Ups\Api\Exception\GenerateTokenForbiddenException;
use ShipStream\Ups\Api\Exception\GenerateTokenTooManyRequestsException;
use ShipStream\Ups\Api\Exception\GenerateTokenUnauthorizedException;
use ShipStream\Ups\Api\Exception\RateBadRequestException;
use ShipStream\Ups\Api\Exception\RateForbiddenException;
use ShipStream\Ups\Api\Exception\RateTooManyRequestsException;
use ShipStream\Ups\Api\Exception\RateUnauthorizedException;
use ShipStream\Ups\Api\Exception\UnexpectedStatusCodeException;
use ShipStream\Ups\Api\Model\Activity;
use ShipStream\Ups\Api\Model\DimensionsUnitOfMeasurement;
use ShipStream\Ups\Api\Model\Error;
use ShipStream\Ups\Api\Model\Package as UpsPackage;
use ShipStream\Ups\Api\Model\PackageDimensions;
use ShipStream\Ups\Api\Model\PackagePackageWeight;
use ShipStream\Ups\Api\Model\PackagePackagingType;
use ShipStream\Ups\Api\Model\PackageWeightUnitOfMeasurement;
use ShipStream\Ups\Api\Model\RateRequest as UpsRateRequest;
use ShipStream\Ups\Api\Model\RateRequestPickupType;
use ShipStream\Ups\Api\Model\RateRequestRequest;
use ShipStream\Ups\Api\Model\RateRequestShipment;
use ShipStream\Ups\Api\Model\RATERequestWrapper;
use ShipStream\Ups\Api\Model\RATEResponseWrapper;
use ShipStream\Ups\Api\Model\RateShipmentPackage;
use ShipStream\Ups\Api\Model\RateShipmentShipFrom;
use ShipStream\Ups\Api\Model\RateShipmentShipper;
use ShipStream\Ups\Api\Model\RateShipmentShipTo;
use ShipStream\Ups\Api\Model\ShipFromAddress;
use ShipStream\Ups\Api\Model\ShipmentShipmentRatingOptions;
use ShipStream\Ups\Api\Model\ShipperAddress;
use ShipStream\Ups\Api\Model\ShipToAddress;
use ShipStream\Ups\Api\Model\TrackApiResponse;
use ShipStream\Ups\Exception\AuthenticationException;

/**
 * UPS through shipstream/ups-rest-php-sdk. Every exception of the SDK, of the HTTP client or of the
 * serializer is translated here to a CarrierException.
 */
final class UpsCarrier implements CarrierInterface
{
    /** The Rating API release the requests are written against, the one `Client::rate()` documents. */
    private const RATING_VERSION = 'v2403';

    /** Rates every UPS service between the two addresses in a single call. */
    private const REQUEST_OPTION_SHOP = 'Shop';

    /** What UPS is told is asking, which it shows in its own logs. */
    private const TRANSACTION_SOURCE = 'sylius-shipping-carriers-plugin';

    /** «02 - Package»: packaging of the shipper's own, not a UPS box. */
    private const PACKAGING_TYPE_PACKAGE = '02';

    /**
     * UPS pickup type codes: 01 Daily Pickup, 03 Customer Counter, 06 One Time Pickup.
     *
     * @var array<string, string>
     */
    private const PICKUP_TYPE_CODES = [
        CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED => '01',
        CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF => '03',
        CarrierCredentialsInterface::PICKUP_TYPE_ON_DEMAND => '06',
    ];

    public function __construct(
        private readonly CredentialsProvider $credentialsProvider,
        private readonly UpsClientFactory $clientFactory,
    ) {
    }

    public function rate(RateRequest $request): RateSet
    {
        try {
            $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_UPS);
            $accountNumber = $credentials->getCredentials()[CarrierCredentialsInterface::ACCOUNT_NUMBER] ?? null;
            $pickupTypeCode = self::PICKUP_TYPE_CODES[(string) $credentials->getPickupType()]
                ?? throw new CarrierCredentialsException(sprintf('"%s" is not a pickup type UPS knows.', (string) $credentials->getPickupType()));

            $response = $this->clientFactory->create($credentials)->rate(
                self::RATING_VERSION,
                self::REQUEST_OPTION_SHOP,
                $this->buildRequest($request, $accountNumber, $pickupTypeCode),
            );
            if (!$response instanceof RATEResponseWrapper) {
                throw new UnexpectedCarrierResponseException('UPS answered the rate request without a rate response.');
            }

            return $this->readRates($response);
        } catch (\Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    public function track(string $trackingNumber): TrackingInfo
    {
        try {
            $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_UPS);

            $response = $this->clientFactory->create($credentials)->getSingleTrackResponseUsingGET(
                $trackingNumber,
                [],
                // UPS wants a different id on every enquiry, and a name for what is asking.
                ['transId' => bin2hex(random_bytes(16)), 'transactionSrc' => self::TRANSACTION_SOURCE],
            );

            if (!$response instanceof TrackApiResponse) {
                throw new UnexpectedCarrierResponseException('UPS answered the tracking enquiry without a tracking response.');
            }

            return $this->readTracking($trackingNumber, $response);
        } catch (\Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    /**
     * UPS answers with one shipment per enquiry and its packages; the plugin reports the first package, which is the
     * one the tracking number names.
     */
    private function readTracking(string $trackingNumber, TrackApiResponse $response): TrackingInfo
    {
        $shipments = $response->getTrackResponse()->getShipment();
        $package = ($shipments[0] ?? null)?->getPackage()[0] ?? null;
        if (!$package instanceof UpsPackage) {
            throw new UnexpectedCarrierResponseException(sprintf('UPS knows no package for the tracking number "%s".', $trackingNumber));
        }

        $events = [];
        foreach ($package->isInitialized('activity') ? $package->getActivity() : [] as $activity) {
            if (!$activity instanceof Activity) {
                continue;
            }

            $description = $activity->isInitialized('status') ? (string) $activity->getStatus()->getDescription() : '';
            if ('' === $description) {
                continue;
            }

            $events[] = new TrackingEvent($this->occurredAt($activity), $description, $this->place($activity));
        }

        $status = $package->isInitialized('currentStatus') ? $package->getCurrentStatus()->getDescription() : null;

        return new TrackingInfo($trackingNumber, '' === $status ? null : $status, $events);
    }

    /**
     * UPS dates its events as `20260917` and `134500`, in the time of the place the event happened.
     */
    private function occurredAt(Activity $activity): ?\DateTimeImmutable
    {
        $date = $activity->isInitialized('date') ? $activity->getDate() : '';
        if (1 !== preg_match('/^\d{8}$/', $date)) {
            return null;
        }

        $time = $activity->isInitialized('time') ? $activity->getTime() : '';
        $occurredAt = \DateTimeImmutable::createFromFormat('YmdHis', $date . (1 === preg_match('/^\d{6}$/', $time) ? $time : '000000'));

        return false === $occurredAt ? null : $occurredAt;
    }

    private function place(Activity $activity): ?string
    {
        $address = $activity->isInitialized('location') ? $activity->getLocation()->getAddress() : null;
        if (null === $address) {
            return null;
        }

        $place = array_filter([
            $address->isInitialized('city') ? $address->getCity() : null,
            $address->isInitialized('stateProvince') ? $address->getStateProvince() : null,
            $address->isInitialized('countryCode') ? $address->getCountryCode() : null,
        ]);

        return [] === $place ? null : implode(', ', $place);
    }

    private function buildRequest(RateRequest $request, ?string $accountNumber, string $pickupTypeCode): RATERequestWrapper
    {
        $shipper = (new RateShipmentShipper())->setAddress($this->address(new ShipperAddress(), $request->origin));
        $shipment = (new RateRequestShipment())
            ->setShipper($shipper)
            ->setShipFrom((new RateShipmentShipFrom())->setAddress($this->address(new ShipFromAddress(), $request->origin)))
            ->setShipTo((new RateShipmentShipTo())->setAddress($this->address(new ShipToAddress(), $request->destination)))
            ->setPackage(array_map($this->package(...), $request->packages))
        ;

        if (null !== $accountNumber) {
            // UPS only returns negotiated rates for a valid account number that has them.
            $shipper->setShipperNumber($accountNumber);
            $shipment->setShipmentRatingOptions((new ShipmentShipmentRatingOptions())->setNegotiatedRatesIndicator('Y'));
        }

        return (new RATERequestWrapper())->setRateRequest(
            (new UpsRateRequest())
                ->setRequest((new RateRequestRequest())->setRequestOption(self::REQUEST_OPTION_SHOP))
                // How packages reach UPS changes the rate chart it prices with.
                ->setPickupType((new RateRequestPickupType())->setCode($pickupTypeCode))
                ->setShipment($shipment),
        );
    }

    /**
     * @template T of ShipperAddress|ShipFromAddress|ShipToAddress
     *
     * @param T $upsAddress
     *
     * @return T
     */
    private function address(ShipperAddress|ShipFromAddress|ShipToAddress $upsAddress, Address $address): ShipperAddress|ShipFromAddress|ShipToAddress
    {
        $upsAddress->setAddressLine([$address->street]);
        $upsAddress->setCity($address->city);
        $upsAddress->setPostalCode($address->postcode);
        $upsAddress->setCountryCode($address->countryCode);
        if (null !== $address->provinceCode) {
            $upsAddress->setStateProvinceCode($address->provinceCode);
        }

        // UPS reads the indicator by its presence alone, so it is only sent for a home.
        if ($upsAddress instanceof ShipToAddress && $address->residential) {
            $upsAddress->setResidentialAddressIndicator('Y');
        }

        return $upsAddress;
    }

    private function package(Package $package): RateShipmentPackage
    {
        // UPS wants the longest side as the length.
        $sides = [$package->length, $package->width, $package->height];
        rsort($sides);

        $dimensions = (new PackageDimensions())
            ->setUnitOfMeasurement((new DimensionsUnitOfMeasurement())->setCode(match ($package->dimensionUnit) {
                CarrierShippingOriginInterface::DIMENSION_UNIT_CM => 'CM',
                default => 'IN',
            }))
            ->setLength($this->roundUp($sides[0], 0))
            ->setWidth($this->roundUp($sides[1], 0))
            ->setHeight($this->roundUp($sides[2], 0))
        ;

        $weight = (new PackagePackageWeight())
            ->setUnitOfMeasurement((new PackageWeightUnitOfMeasurement())->setCode(match ($package->weightUnit) {
                CarrierShippingOriginInterface::WEIGHT_UNIT_KG => 'KGS',
                default => 'LBS',
            }))
            ->setWeight($this->roundUp($package->weight, 1))
        ;

        return (new RateShipmentPackage())
            ->setPackagingType((new PackagePackagingType())->setCode(self::PACKAGING_TYPE_PACKAGE))
            ->setDimensions($dimensions)
            ->setPackageWeight($weight)
        ;
    }

    /**
     * UPS takes each measure in three characters and the weight in five, so a measure is sent whole and the
     * weight to a tenth. Rounding up keeps the declared package from ever being smaller than the real one.
     */
    private function roundUp(float $value, int $decimals): string
    {
        $factor = 10 ** $decimals;
        // Rounding first drops the float noise that would otherwise push 12.000000000000002 up to 13.
        $rounded = number_format(ceil(round($value * $factor, 6)) / $factor, $decimals, '.', '');

        // A trailing zero only goes after the decimal point: 5.0 is sent as 5, but 30 stays 30.
        return $decimals > 0 ? rtrim(rtrim($rounded, '0'), '.') : $rounded;
    }

    private function readRates(RATEResponseWrapper $response): RateSet
    {
        if (!$response->isInitialized('rateResponse') || !$response->getRateResponse()->isInitialized('ratedShipment')) {
            throw new UnexpectedCarrierResponseException('UPS answered the rate request without any rated shipment.');
        }

        $rates = [];
        foreach ($response->getRateResponse()->getRatedShipment() as $ratedShipment) {
            // The negotiated charge is what UPS bills the account; without one, the published charge applies.
            $charge = $ratedShipment->getNegotiatedRateCharges()?->getTotalCharge() ?? $ratedShipment->getTotalCharges();

            $rates[] = new Rate(
                $ratedShipment->getService()->getCode(),
                MinorUnits::fromDecimal($charge->getMonetaryValue()),
                $charge->getCurrencyCode(),
            );
        }

        return new RateSet($rates);
    }

    private function translate(\Throwable $exception): CarrierException
    {
        return match (true) {
            $exception instanceof CarrierException => $exception,
            $exception instanceof RateUnauthorizedException,
            $exception instanceof RateForbiddenException,
            $exception instanceof GenerateTokenBadRequestException,
            $exception instanceof GenerateTokenUnauthorizedException,
            $exception instanceof GenerateTokenForbiddenException => new CarrierCredentialsException(sprintf('UPS rejected the credentials: %s', $this->errors($exception)), 0, $exception),
            $exception instanceof RateBadRequestException => new CarrierRejectedRequestException(sprintf('UPS rejected the rate request: %s', $this->errors($exception)), 0, $exception),
            $exception instanceof RateTooManyRequestsException,
            $exception instanceof GenerateTokenTooManyRequestsException => new CarrierUnavailableException('UPS refused the request: too many requests.', 0, $exception),
            // The SDK wraps whatever failed while getting the access token; the cause decides.
            $exception instanceof AuthenticationException => null !== $exception->getPrevious()
                ? $this->translate($exception->getPrevious())
                : new CarrierCredentialsException(sprintf('UPS did not grant an access token: %s', $exception->getMessage()), 0, $exception),
            $exception instanceof UnexpectedStatusCodeException => $exception->getCode() >= 500
                ? new CarrierUnavailableException(sprintf('UPS answered with HTTP %d.', $exception->getCode()), 0, $exception)
                : new UnexpectedCarrierResponseException(sprintf('UPS answered with an unexpected HTTP %d.', $exception->getCode()), 0, $exception),
            $exception instanceof ClientExceptionInterface => new CarrierUnavailableException(sprintf('UPS could not be reached: %s', $exception->getMessage()), 0, $exception),
            default => new UnexpectedCarrierResponseException(sprintf('UPS answered with something the plugin cannot read: %s', $exception->getMessage()), 0, $exception),
        };
    }

    private function errors(
        RateBadRequestException|RateUnauthorizedException|RateForbiddenException|GenerateTokenBadRequestException|GenerateTokenUnauthorizedException|GenerateTokenForbiddenException $exception,
    ): string {
        try {
            return implode(' - ', array_map(
                static fn (Error $error): string => sprintf('%s: %s', $error->getCode(), $error->getMessage()),
                $exception->getErrorResponse()->getResponse()->getErrors(),
            ));
        } catch (\Throwable) {
            // An error body without the expected shape still leaves the status behind the message.
            return $exception->getMessage();
        }
    }
}
