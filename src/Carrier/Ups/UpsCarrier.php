<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

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
use ShipStream\Ups\Api\Model\Activity;
use ShipStream\Ups\Api\Model\DimensionsUnitOfMeasurement;
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

    /** What is being asked of UPS, for the message when it refuses. */
    private const RATE_OPERATION = 'the rate request';

    private const TRACKING_OPERATION = 'the tracking request';

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
        private readonly UpsErrorTranslator $errorTranslator = new UpsErrorTranslator(),
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
            throw $this->errorTranslator->translate($exception, self::RATE_OPERATION);
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
            throw $this->errorTranslator->translate($exception, self::TRACKING_OPERATION);
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
     * UPS dates its events as `20260917` and `134500`, in the time of the place the event happened, and gives
     * that place's offset from UTC alongside as `-05:00`. Read without the offset, the server lends the event
     * its own timezone: the buyer is shown an hour that is neither, and two scans in different zones can come
     * out in the wrong order, which is what the buyer actually reads.
     *
     * The offset is taken from `gmtOffset` and not from `gmtDate`/`gmtTime`, whose own documentation says only
     * «gmtDate» and «gmtTime» and whose example for the time comes without its leading zero. Whether those two
     * are already UTC is a guess nobody here can settle: no real answer from UPS has been seen, because the
     * fixtures were written from the SDK's `openapi.yaml`. `date` and `time` are documented, so they are what
     * this reads. Without an offset it behaves as it always did.
     */
    private function occurredAt(Activity $activity): ?\DateTimeImmutable
    {
        $date = $activity->isInitialized('date') ? $activity->getDate() : '';
        if (1 !== preg_match('/^\d{8}$/', $date)) {
            return null;
        }

        $time = $activity->isInitialized('time') ? $activity->getTime() : '';
        $occurredAt = \DateTimeImmutable::createFromFormat(
            'YmdHis',
            $date . (1 === preg_match('/^\d{6}$/', $time) ? $time : '000000'),
            self::whereItHappened($activity),
        );

        return false === $occurredAt ? null : $occurredAt;
    }

    /**
     * Null when UPS said nothing usable about the offset, which leaves the date where it has always been: the
     * timezone of whoever is running this.
     */
    private static function whereItHappened(Activity $activity): ?\DateTimeZone
    {
        $offset = $activity->isInitialized('gmtOffset') ? trim($activity->getGmtOffset()) : '';
        if (1 !== preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
            return null;
        }

        try {
            return new \DateTimeZone($offset);
        } catch (\Exception) {
            return null;
        }
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
}
