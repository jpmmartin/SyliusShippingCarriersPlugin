<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\IssuedLabel;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentResult;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package;
use ShipStream\Ups\Api\Model\DimensionsUnitOfMeasurement;
use ShipStream\Ups\Api\Model\LabelSpecificationLabelImageFormat;
use ShipStream\Ups\Api\Model\LabelSpecificationLabelStockSize;
use ShipStream\Ups\Api\Model\PackageDimensions;
use ShipStream\Ups\Api\Model\PackagePackageWeight;
use ShipStream\Ups\Api\Model\PackagePackaging;
use ShipStream\Ups\Api\Model\PackageWeightUnitOfMeasurement;
use ShipStream\Ups\Api\Model\PaymentInformationShipmentCharge;
use ShipStream\Ups\Api\Model\ShipFromAddress;
use ShipStream\Ups\Api\Model\ShipFromPhone;
use ShipStream\Ups\Api\Model\ShipmentChargeBillShipper;
use ShipStream\Ups\Api\Model\ShipmentPackage as UpsShipmentPackage;
use ShipStream\Ups\Api\Model\ShipmentPaymentInformation;
use ShipStream\Ups\Api\Model\ShipmentRequest as UpsShipmentRequest;
use ShipStream\Ups\Api\Model\ShipmentRequestLabelSpecification;
use ShipStream\Ups\Api\Model\ShipmentRequestRequest;
use ShipStream\Ups\Api\Model\ShipmentRequestShipment;
use ShipStream\Ups\Api\Model\ShipmentService;
use ShipStream\Ups\Api\Model\ShipmentShipFrom;
use ShipStream\Ups\Api\Model\ShipmentShipper;
use ShipStream\Ups\Api\Model\ShipmentShipTo;
use ShipStream\Ups\Api\Model\ShipperAddress;
use ShipStream\Ups\Api\Model\ShipperPhone;
use ShipStream\Ups\Api\Model\SHIPRequestWrapper;
use ShipStream\Ups\Api\Model\SHIPResponseWrapper;
use ShipStream\Ups\Api\Model\ShipToAddress;
use ShipStream\Ups\Api\Model\ShipToPhone;

/**
 * Issues UPS labels through shipstream/ups-rest-php-sdk. Every exception of the SDK, of the HTTP client or of
 * the serializer is translated to a CarrierException by UpsErrorTranslator.
 */
final readonly class UpsLabelCarrier implements LabelCarrierInterface
{
    /** The Shipping API release the requests are written against, the one `Client::shipment()` documents. */
    private const SHIPPING_VERSION = 'v2403';

    /** What is being asked of UPS, for the message when it refuses. */
    private const SHIP_OPERATION = 'the shipment request';

    /**
     * «nonvalidate»: UPS accepts the addresses as given instead of refusing anything it would rather correct.
     * The buyer's address was already good enough to be rated and paid for; refusing to print now would leave
     * a paid order nobody can ship.
     */
    private const REQUEST_OPTION_NON_VALIDATE = 'nonvalidate';

    /** «02 - Package»: packaging of the shipper's own, not a UPS box. */
    private const PACKAGING_TYPE_PACKAGE = '02';

    /** «01 - Transportation», billed to the shipper's own account. */
    private const CHARGE_TYPE_TRANSPORTATION = '01';

    /** Four by six inches, the size of every label printer and of a sheet folded in half. */
    private const LABEL_STOCK_HEIGHT = '6';

    private const LABEL_STOCK_WIDTH = '4';

    public function __construct(
        private CredentialsProvider $credentialsProvider,
        private UpsClientFactory $clientFactory,
        private LabelFormats $labelFormats,
        private UpsErrorTranslator $errorTranslator = new UpsErrorTranslator(),
    ) {
    }

    public function ship(ShipmentRequest $request): ShipmentResult
    {
        $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_UPS);
        $accountNumber = $credentials->getCredentials()[CarrierCredentialsInterface::ACCOUNT_NUMBER]
            ?? throw new CarrierCredentialsException('UPS does not issue labels without an account number.');

        foreach ([$request->origin, $request->destination] as $address) {
            if (!$address->hasContact()) {
                throw new CarrierRejectedRequestException('UPS does not print a label without a name and a phone at both ends.');
            }
        }

        try {
            $response = $this->clientFactory->create($credentials)->shipment(
                self::SHIPPING_VERSION,
                $this->buildRequest($request, $accountNumber),
            );
        } catch (\Throwable $exception) {
            throw $this->errorTranslator->translate($exception, self::SHIP_OPERATION);
        }

        if (!$response instanceof SHIPResponseWrapper) {
            throw new UnexpectedCarrierResponseException('UPS answered the shipment request with something that is not a shipment response.');
        }

        return $this->readResult($response, $request);
    }

    public function void(string $carrierReference): VoidResult
    {
        throw new \LogicException('Voiding a UPS shipment is not wired yet.');
    }

    public function recover(string $carrierReference): ?ShipmentResult
    {
        throw new \LogicException('Recovering a UPS shipment is not wired yet.');
    }

    private function buildRequest(ShipmentRequest $request, string $accountNumber): SHIPRequestWrapper
    {
        $shipper = (new ShipmentShipper())
            ->setName((string) $request->origin->name())
            ->setAttentionName((string) $request->origin->contactName)
            ->setPhone($this->phone(new ShipperPhone(), $request->origin))
            ->setShipperNumber($accountNumber)
            ->setAddress($this->address(new ShipperAddress(), $request->origin))
        ;

        $shipment = (new ShipmentRequestShipment())
            ->setDescription($this->description($request))
            ->setShipper($shipper)
            // Where it leaves from, which is the same address the rate was asked for.
            ->setShipFrom(
                (new ShipmentShipFrom())
                    ->setName((string) $request->origin->name())
                    ->setAttentionName((string) $request->origin->contactName)
                    ->setPhone($this->phone(new ShipFromPhone(), $request->origin))
                    ->setAddress($this->address(new ShipFromAddress(), $request->origin)),
            )
            ->setShipTo(
                (new ShipmentShipTo())
                    ->setName((string) $request->destination->name())
                    ->setAttentionName((string) $request->destination->contactName)
                    ->setPhone($this->phone(new ShipToPhone(), $request->destination))
                    ->setAddress($this->address(new ShipToAddress(), $request->destination)),
            )
            ->setPaymentInformation(
                (new ShipmentPaymentInformation())->setShipmentCharge([
                    (new PaymentInformationShipmentCharge())
                        ->setType(self::CHARGE_TYPE_TRANSPORTATION)
                        ->setBillShipper((new ShipmentChargeBillShipper())->setAccountNumber($accountNumber)),
                ]),
            )
            ->setService((new ShipmentService())->setCode($request->serviceCode))
            ->setPackage(array_map($this->package(...), $request->packages))
        ;

        return (new SHIPRequestWrapper())->setShipmentRequest(
            (new UpsShipmentRequest())
                ->setRequest((new ShipmentRequestRequest())->setRequestOption(self::REQUEST_OPTION_NON_VALIDATE))
                ->setShipment($shipment)
                ->setLabelSpecification(
                    (new ShipmentRequestLabelSpecification())
                        ->setLabelImageFormat(
                            (new LabelSpecificationLabelImageFormat())->setCode($this->labelFormats->for(CarrierCredentialsInterface::CARRIER_UPS)),
                        )
                        ->setLabelStockSize(
                            (new LabelSpecificationLabelStockSize())
                                ->setHeight(self::LABEL_STOCK_HEIGHT)
                                ->setWidth(self::LABEL_STOCK_WIDTH),
                        ),
                ),
        );
    }

    /**
     * Every party has its own address class in UPS's schema, with the same fields.
     *
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

    private function package(ShipmentPackage $shipmentPackage): UpsShipmentPackage
    {
        $package = $shipmentPackage->package;

        // UPS wants the longest side as the length.
        $sides = [$package->length, $package->width, $package->height];
        rsort($sides);

        return (new UpsShipmentPackage())
            ->setPackaging((new PackagePackaging())->setCode(self::PACKAGING_TYPE_PACKAGE))
            ->setDimensions(
                (new PackageDimensions())
                    ->setUnitOfMeasurement((new DimensionsUnitOfMeasurement())->setCode(match ($package->dimensionUnit) {
                        CarrierShippingOriginInterface::DIMENSION_UNIT_CM => 'CM',
                        default => 'IN',
                    }))
                    ->setLength($this->roundUp($sides[0], 0))
                    ->setWidth($this->roundUp($sides[1], 0))
                    ->setHeight($this->roundUp($sides[2], 0)),
            )
            ->setPackageWeight(
                (new PackagePackageWeight())
                    ->setUnitOfMeasurement((new PackageWeightUnitOfMeasurement())->setCode(match ($package->weightUnit) {
                        CarrierShippingOriginInterface::WEIGHT_UNIT_KG => 'KGS',
                        default => 'LBS',
                    }))
                    ->setWeight($this->roundUp($package->weight, 1)),
            )
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

    /**
     * UPS requires a description on an international shipment and ignores it on a domestic one.
     */
    private function description(ShipmentRequest $request): string
    {
        return $request->crossesABorder() ? 'Goods' : '';
    }

    /**
     * And its own phone class, likewise.
     *
     * @template T of ShipperPhone|ShipFromPhone|ShipToPhone
     *
     * @param T $phone
     *
     * @return T
     */
    private function phone(ShipperPhone|ShipFromPhone|ShipToPhone $phone, Address $address): ShipperPhone|ShipFromPhone|ShipToPhone
    {
        $phone->setNumber((string) $address->phone);

        return $phone;
    }

    private function readResult(SHIPResponseWrapper $response, ShipmentRequest $request): ShipmentResult
    {
        $results = $response->isInitialized('shipmentResponse') ? $response->getShipmentResponse()->getShipmentResults() : null;
        $reference = null === $results ? '' : (string) $results->getShipmentIdentificationNumber();
        $packageResults = null === $results || !$results->isInitialized('packageResults') ? [] : ($results->getPackageResults() ?? []);

        if ('' === $reference || [] === $packageResults) {
            throw new UnexpectedCarrierResponseException('UPS answered the shipment request without a shipment identification number or without any package.');
        }

        // One label per package, or the plugin would store fewer labels than there are parcels to put them on.
        if (\count($packageResults) !== \count($request->packages)) {
            throw new UnexpectedCarrierResponseException(sprintf(
                'UPS answered with %d label(s) for a shipment of %d package(s).',
                \count($packageResults),
                \count($request->packages),
            ));
        }

        $labels = [];
        foreach (array_values($packageResults) as $position => $packageResult) {
            $label = $packageResult->isInitialized('shippingLabel') ? $packageResult->getShippingLabel() : null;
            $image = null === $label ? '' : (string) $label->getGraphicImage();
            $contents = '' === $image ? false : base64_decode($image, true);
            if (false === $contents || '' === $contents) {
                throw new UnexpectedCarrierResponseException('UPS answered the shipment request with a package that carries no readable label.');
            }

            $labels[] = new IssuedLabel(
                $position,
                (string) $packageResult->getTrackingNumber(),
                null === $label || !$label->isInitialized('imageFormat') ? '' : (string) $label->getImageFormat()->getCode(),
                $contents,
            );
        }

        return new ShipmentResult($reference, $labels);
    }
}
