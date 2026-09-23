<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Ups;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\CredentialsProvider;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsDocument;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsInvoice;
use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsItem;
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
use ShipStream\Ups\Api\Model\ContactsSoldTo;
use ShipStream\Ups\Api\Model\DimensionsUnitOfMeasurement;
use ShipStream\Ups\Api\Model\InternationalFormsContacts;
use ShipStream\Ups\Api\Model\InternationalFormsProduct;
use ShipStream\Ups\Api\Model\LabelRecoveryRequest;
use ShipStream\Ups\Api\Model\LabelRecoveryRequestReferenceValues;
use ShipStream\Ups\Api\Model\LabelRecoveryRequestRequest;
use ShipStream\Ups\Api\Model\LABELRECOVERYRequestWrapper;
use ShipStream\Ups\Api\Model\LabelRecoveryResponse;
use ShipStream\Ups\Api\Model\LABELRECOVERYResponseWrapper;
use ShipStream\Ups\Api\Model\LabelSpecificationLabelImageFormat;
use ShipStream\Ups\Api\Model\LabelSpecificationLabelStockSize;
use ShipStream\Ups\Api\Model\PackageDimensions;
use ShipStream\Ups\Api\Model\PackagePackageWeight;
use ShipStream\Ups\Api\Model\PackagePackaging;
use ShipStream\Ups\Api\Model\PackageReferenceNumber;
use ShipStream\Ups\Api\Model\PackageWeightUnitOfMeasurement;
use ShipStream\Ups\Api\Model\PaymentInformationShipmentCharge;
use ShipStream\Ups\Api\Model\ProductUnit;
use ShipStream\Ups\Api\Model\ReferenceValuesReferenceNumber;
use ShipStream\Ups\Api\Model\ShipFromAddress;
use ShipStream\Ups\Api\Model\ShipFromPhone;
use ShipStream\Ups\Api\Model\ShipmentChargeBillShipper;
use ShipStream\Ups\Api\Model\ShipmentInvoiceLineTotal;
use ShipStream\Ups\Api\Model\ShipmentPackage as UpsShipmentPackage;
use ShipStream\Ups\Api\Model\ShipmentPaymentInformation;
use ShipStream\Ups\Api\Model\ShipmentReferenceNumber;
use ShipStream\Ups\Api\Model\ShipmentRequest as UpsShipmentRequest;
use ShipStream\Ups\Api\Model\ShipmentRequestLabelSpecification;
use ShipStream\Ups\Api\Model\ShipmentRequestRequest;
use ShipStream\Ups\Api\Model\ShipmentRequestShipment;
use ShipStream\Ups\Api\Model\ShipmentResponseShipmentResults;
use ShipStream\Ups\Api\Model\ShipmentService;
use ShipStream\Ups\Api\Model\ShipmentServiceOptionsInternationalForms;
use ShipStream\Ups\Api\Model\ShipmentShipFrom;
use ShipStream\Ups\Api\Model\ShipmentShipmentServiceOptions;
use ShipStream\Ups\Api\Model\ShipmentShipper;
use ShipStream\Ups\Api\Model\ShipmentShipTo;
use ShipStream\Ups\Api\Model\ShipperAddress;
use ShipStream\Ups\Api\Model\ShipperPhone;
use ShipStream\Ups\Api\Model\SHIPRequestWrapper;
use ShipStream\Ups\Api\Model\SHIPResponseWrapper;
use ShipStream\Ups\Api\Model\ShipToAddress;
use ShipStream\Ups\Api\Model\ShipToPhone;
use ShipStream\Ups\Api\Model\SoldToAddress;
use ShipStream\Ups\Api\Model\SoldToPhone;
use ShipStream\Ups\Api\Model\UnitUnitOfMeasurement;
use ShipStream\Ups\Api\Model\VOIDSHIPMENTResponseWrapper;

/**
 * Issues UPS labels through shipstream/ups-rest-php-sdk. Every exception of the SDK, of the HTTP client or of
 * the serializer is translated to a CarrierException by UpsErrorTranslator.
 *
 * @internal
 */
final readonly class UpsLabelCarrier implements LabelCarrierInterface
{
    /** The Shipping API release the requests are written against, the one `Client::shipment()` documents. */
    private const SHIPPING_VERSION = 'v2403';

    /** The Void Shipping API release, the one `Client::voidShipment()` documents. */
    private const VOID_VERSION = 'v2403';

    /** The Label Recovery API release, the one `Client::labelRecovery()` documents. */
    private const LABEL_RECOVERY_VERSION = 'v1';

    /** What is being asked of UPS, for the message when it refuses. */
    private const SHIP_OPERATION = 'the shipment request';

    private const VOID_OPERATION = 'the void request';

    private const RECOVER_OPERATION = 'the label recovery';

    /** «1 - Success» in the summary of a void. */
    private const VOID_STATUS_SUCCESS = '1';

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

    /** «02 - Duties and Taxes», sent only when the store pays them. */
    private const CHARGE_TYPE_DUTIES_AND_TAXES = '02';

    /** «01 - Invoice», the commercial invoice customs reads. */
    private const FORM_TYPE_INVOICE = '01';

    private const REASON_FOR_EXPORT_SALE = 'SALE';

    /**
     * Who pays the duties, as UPS prints it on the invoice. Its schema lists DDU and DDP, not DAP.
     *
     * @var array<string, string>
     */
    private const TERMS_OF_SHIPMENT = [
        CarrierCredentialsInterface::DUTIES_PAYER_RECIPIENT => 'DDU',
        CarrierCredentialsInterface::DUTIES_PAYER_SHIPPER => 'DDP',
    ];

    /** Pieces: every line of the invoice is counted in units sold. */
    private const PRODUCT_UNIT_OF_MEASUREMENT = 'PCS';

    /** How long each of the at most three lines of a product's description may be. */
    private const PRODUCT_DESCRIPTION_LINE_LENGTH = 35;

    private const PRODUCT_DESCRIPTION_LINES = 3;

    private const PART_NUMBER_LENGTH = 35;

    /**
     * UPS wants the invoice total on the shipment itself, and only there, when it leaves the United States for
     * Puerto Rico or Canada.
     *
     * @var list<string>
     */
    private const INVOICE_LINE_TOTAL_DESTINATIONS_FROM_US = ['PR', 'CA'];

    /**
     * The members of the European Union. Between two of them UPS treats a shipment as a «Qualified Domestic
     * Shipment», for which a duties and taxes charge is not valid.
     *
     * @var list<string>
     */
    private const EUROPEAN_UNION = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * Where UPS takes a reference of the shipper's own. Its schema splits it in two and each half refuses the
     * other's case: `PackageReferenceNumber` says it is «valid if the origin/destination pair is US/US or
     * PR/PR», and `ShipmentReferenceNumber` says it is valid when it is not. So the same reference goes on
     * every package of a domestic shipment and on the shipment itself otherwise.
     *
     * @var list<string>
     */
    private const PACKAGE_REFERENCE_COUNTRIES = ['US', 'PR'];

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
                $this->buildRequest($request, $accountNumber, $credentials->getDutiesPayer()),
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
        $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_UPS);

        try {
            $response = $this->clientFactory->create($credentials)->voidShipment(self::VOID_VERSION, $carrierReference);
        } catch (\Throwable $exception) {
            $translated = $this->errorTranslator->translate($exception, self::VOID_OPERATION);

            // UPS saying «no» is an answer, not a failure of the plugin: the label stays issued, and the
            // warehouse has to be told that rather than left thinking the parcel was cancelled.
            if ($translated instanceof CarrierRejectedRequestException) {
                return VoidResult::refused($translated->getMessage());
            }

            throw $translated;
        }

        if (!$response instanceof VOIDSHIPMENTResponseWrapper || !$response->isInitialized('voidShipmentResponse')) {
            throw new UnexpectedCarrierResponseException('UPS answered the void request with something that is not a void response.');
        }

        $voidResponse = $response->getVoidShipmentResponse();
        $status = $voidResponse->isInitialized('summaryResult') ? $voidResponse->getSummaryResult()->getStatus() : null;
        $code = null === $status ? '' : (string) $status->getCode();

        if (self::VOID_STATUS_SUCCESS !== $code) {
            return VoidResult::refused(sprintf(
                'UPS did not void the shipment: %s',
                null === $status || '' === (string) $status->getDescription() ? 'it gave no reason.' : (string) $status->getDescription(),
            ));
        }

        return VoidResult::voided();
    }

    public function recover(string $ownReference): ?ShipmentResult
    {
        $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_UPS);
        $accountNumber = $credentials->getCredentials()[CarrierCredentialsInterface::ACCOUNT_NUMBER]
            ?? throw new CarrierCredentialsException('UPS is not asked about a shipment without an account number.');

        try {
            $response = $this->clientFactory->create($credentials)->labelRecovery(
                self::LABEL_RECOVERY_VERSION,
                (new LABELRECOVERYRequestWrapper())->setLabelRecoveryRequest(
                    (new LabelRecoveryRequest())
                        ->setRequest(new LabelRecoveryRequestRequest())
                        ->setReferenceValues(
                            (new LabelRecoveryRequestReferenceValues())
                                ->setReferenceNumber((new ReferenceValuesReferenceNumber())->setValue($ownReference))
                                ->setShipperNumber($accountNumber),
                        ),
                ),
            );
        } catch (CarrierRejectedRequestException) {
            // UPS knows no shipment by that reference, which is the answer: it never issued it.
            return null;
        } catch (\Throwable $exception) {
            $translated = $this->errorTranslator->translate($exception, self::RECOVER_OPERATION);
            if ($translated instanceof CarrierRejectedRequestException) {
                return null;
            }

            throw $translated;
        }

        if (!$response instanceof LABELRECOVERYResponseWrapper || !$response->isInitialized('labelRecoveryResponse')) {
            throw new UnexpectedCarrierResponseException('UPS answered the label recovery with something that is not a recovery response.');
        }

        return $this->readRecovered($response->getLabelRecoveryResponse());
    }

    private function readRecovered(LabelRecoveryResponse $recovered): ?ShipmentResult
    {
        $reference = (string) $recovered->getShipmentIdentificationNumber();
        $results = $recovered->isInitialized('labelResults') ? $recovered->getLabelResults() : [];
        if ('' === $reference || [] === $results) {
            return null;
        }

        $labels = [];
        foreach (array_values($results) as $position => $result) {
            $image = $result->isInitialized('labelImage') ? $result->getLabelImage() : null;
            $contents = null === $image ? false : base64_decode((string) $image->getGraphicImage(), true);
            if (false === $contents || '' === $contents) {
                throw new UnexpectedCarrierResponseException('UPS recovered a shipment with a label that cannot be read.');
            }

            $labels[] = new IssuedLabel(
                $position,
                (string) $result->getTrackingNumber(),
                null === $image || !$image->isInitialized('labelImageFormat') ? '' : (string) $image->getLabelImageFormat()->getCode(),
                $contents,
            );
        }

        return new ShipmentResult($reference, $labels);
    }

    private function buildRequest(ShipmentRequest $request, string $accountNumber, string $dutiesPayer): SHIPRequestWrapper
    {
        $charges = [
            (new PaymentInformationShipmentCharge())
                ->setType(self::CHARGE_TYPE_TRANSPORTATION)
                ->setBillShipper((new ShipmentChargeBillShipper())->setAccountNumber($accountNumber)),
        ];

        // Left out when the recipient pays: billing the recipient takes a UPS account of the recipient's own,
        // which a buyer does not have.
        if (null !== $request->customsInvoice &&
            CarrierCredentialsInterface::DUTIES_PAYER_SHIPPER === $dutiesPayer &&
            !self::isQualifiedDomestic($request)
        ) {
            $charges[] = (new PaymentInformationShipmentCharge())
                ->setType(self::CHARGE_TYPE_DUTIES_AND_TAXES)
                ->setBillShipper((new ShipmentChargeBillShipper())->setAccountNumber($accountNumber));
        }

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
            ->setPaymentInformation((new ShipmentPaymentInformation())->setShipmentCharge($charges))
            ->setService((new ShipmentService())->setCode($request->serviceCode))
            ->setPackage(array_map(
                fn (ShipmentPackage $package): UpsShipmentPackage => $this->package(
                    $package,
                    self::takesAPackageReference($request) ? $request->ownReference : null,
                ),
                $request->packages,
            ))
        ;

        // Nothing else the plugin sends survives a request that goes unanswered, so this is what it will ask
        // UPS about if no answer comes back.
        if (!self::takesAPackageReference($request)) {
            $shipment->setReferenceNumber([(new ShipmentReferenceNumber())->setValue($request->ownReference)]);
        }

        // The invoice is asked for in the same request, so it comes back with the labels.
        if (null !== $request->customsInvoice) {
            $shipment->setShipmentServiceOptions(
                (new ShipmentShipmentServiceOptions())->setInternationalForms(
                    $this->invoice($request, $request->customsInvoice, $dutiesPayer),
                ),
            );

            if ('US' === strtoupper($request->origin->countryCode) &&
                \in_array(strtoupper($request->destination->countryCode), self::INVOICE_LINE_TOTAL_DESTINATIONS_FROM_US, true)
            ) {
                $shipment->setInvoiceLineTotal(
                    (new ShipmentInvoiceLineTotal())
                        ->setCurrencyCode($request->customsInvoice->currencyCode)
                        ->setMonetaryValue(self::amount($request->customsInvoice->total())),
                );
            }
        }

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

    private function invoice(ShipmentRequest $request, CustomsInvoice $invoice, string $dutiesPayer): ShipmentServiceOptionsInternationalForms
    {
        $destination = $request->destination;
        $soldToAddress = (new SoldToAddress())
            ->setAddressLine([$destination->street])
            ->setCity($destination->city)
            ->setPostalCode($destination->postcode)
            ->setCountryCode($destination->countryCode);
        if (null !== $destination->provinceCode) {
            $soldToAddress->setStateProvinceCode($destination->provinceCode);
        }

        return (new ShipmentServiceOptionsInternationalForms())
            ->setFormType([self::FORM_TYPE_INVOICE])
            ->setInvoiceNumber($invoice->number)
            ->setInvoiceDate($invoice->date->format('Ymd'))
            ->setReasonForExport(self::REASON_FOR_EXPORT_SALE)
            ->setCurrencyCode($invoice->currencyCode)
            ->setTermsOfShipment(self::TERMS_OF_SHIPMENT[$dutiesPayer] ?? self::TERMS_OF_SHIPMENT[CarrierCredentialsInterface::DUTIES_PAYER_RECIPIENT])
            // UPS requires whom it was sold to on an invoice: the buyer, who is also who receives it.
            ->setContacts((new InternationalFormsContacts())->setSoldTo(
                (new ContactsSoldTo())
                    ->setName((string) $destination->name())
                    ->setAttentionName((string) $destination->contactName)
                    ->setPhone((new SoldToPhone())->setNumber((string) $destination->phone))
                    ->setAddress($soldToAddress),
            ))
            ->setProduct(array_map(self::product(...), $invoice->lines));
    }

    private static function product(CustomsItem $line): InternationalFormsProduct
    {
        $description = mb_str_split($line->description, self::PRODUCT_DESCRIPTION_LINE_LENGTH);

        return (new InternationalFormsProduct())
            ->setDescription(\array_slice([] === $description ? [$line->code] : $description, 0, self::PRODUCT_DESCRIPTION_LINES))
            ->setUnit(
                (new ProductUnit())
                    ->setNumber((string) $line->quantity)
                    ->setValue(self::amount($line->unitValue))
                    ->setUnitOfMeasurement((new UnitUnitOfMeasurement())->setCode(self::PRODUCT_UNIT_OF_MEASUREMENT)),
            )
            ->setCommodityCode($line->hsCode)
            ->setPartNumber(mb_substr($line->code, 0, self::PART_NUMBER_LENGTH))
            ->setOriginCountryCode($line->countryOfOrigin);
    }

    /**
     * Whether UPS treats the shipment as domestic even though it crosses a border: from the United States to
     * Puerto Rico or back, or between two members of the European Union.
     */
    private static function isQualifiedDomestic(ShipmentRequest $request): bool
    {
        $pair = [strtoupper($request->origin->countryCode), strtoupper($request->destination->countryCode)];
        sort($pair);

        return ['PR', 'US'] === $pair ||
            (\in_array($pair[0], self::EUROPEAN_UNION, true) && \in_array($pair[1], self::EUROPEAN_UNION, true));
    }

    /**
     * An amount in hundredths, as UPS reads money: units, a point and two decimals.
     */
    private static function amount(int $hundredths): string
    {
        return number_format($hundredths / 100, 2, '.', '');
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

    /**
     * @param string|null $ownReference The plugin's reference, on every package, when UPS takes it there
     */
    private function package(ShipmentPackage $shipmentPackage, ?string $ownReference): UpsShipmentPackage
    {
        $package = $shipmentPackage->package;

        // UPS wants the longest side as the length.
        $sides = [$package->length, $package->width, $package->height];
        rsort($sides);

        $upsPackage = (new UpsShipmentPackage())
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

        if (null !== $ownReference) {
            $upsPackage->setReferenceNumber([(new PackageReferenceNumber())->setValue($ownReference)]);
        }

        return $upsPackage;
    }

    /**
     * Whether this shipment is one of the two cases in which UPS takes the reference on the package rather
     * than on the shipment.
     */
    private static function takesAPackageReference(ShipmentRequest $request): bool
    {
        $countryCode = strtoupper($request->origin->countryCode);

        return $countryCode === strtoupper($request->destination->countryCode) &&
            \in_array($countryCode, self::PACKAGE_REFERENCE_COUNTRIES, true);
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

        return new ShipmentResult($reference, $labels, null === $request->customsInvoice || null === $results ? null : $this->readForms($results));
    }

    /**
     * Every form UPS printed for the shipment comes back as a single file. None, or one that cannot be read, is
     * no document at all: the labels are issued and paid for all the same.
     */
    private function readForms(ShipmentResponseShipmentResults $results): ?CustomsDocument
    {
        $form = $results->isInitialized('form') ? $results->getForm() : null;
        $image = null !== $form && $form->isInitialized('image') ? $form->getImage() : null;
        $contents = null === $image || !$image->isInitialized('graphicImage') ? false : base64_decode((string) $image->getGraphicImage(), true);
        if (null === $image || false === $contents || '' === $contents) {
            return null;
        }

        return new CustomsDocument(
            $image->isInitialized('imageFormat') ? (string) $image->getImageFormat()->getCode() : '',
            $contents,
        );
    }
}
