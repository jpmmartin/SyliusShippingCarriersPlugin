<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Carrier\Fedex;

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
use ShipStream\FedEx\Api\ShipV1\Dto\Dimensions;
use ShipStream\FedEx\Api\ShipV1\Dto\FullSchemaCancelShipment;
use ShipStream\FedEx\Api\ShipV1\Dto\FullSchemaShip;
use ShipStream\FedEx\Api\ShipV1\Dto\LabelSpecification;
use ShipStream\FedEx\Api\ShipV1\Dto\PartyAddress;
use ShipStream\FedEx\Api\ShipV1\Dto\PartyContact;
use ShipStream\FedEx\Api\ShipV1\Dto\Payment;
use ShipStream\FedEx\Api\ShipV1\Dto\RecipientsParty;
use ShipStream\FedEx\Api\ShipV1\Dto\RequestedPackageLineItem;
use ShipStream\FedEx\Api\ShipV1\Dto\RequestedShipment;
use ShipStream\FedEx\Api\ShipV1\Dto\ShipperAccountNumber;
use ShipStream\FedEx\Api\ShipV1\Dto\ShipperParty;
use ShipStream\FedEx\Api\ShipV1\Dto\Weight;
use ShipStream\FedEx\Api\ShipV1\Responses\ShpcResponseVoCancelShipment;
use ShipStream\FedEx\Api\ShipV1\Responses\ShpcResponseVoShipShipment;

/**
 * Issues and cancels FedEx labels through shipstream/fedex-rest-sdk. Every exception of the SDK, of Saloon or
 * of the JSON decoding is translated to a CarrierException by FedexErrorTranslator.
 */
final readonly class FedexLabelCarrier implements LabelCarrierInterface
{
    /** What is being asked of FedEx, for the message when it refuses. */
    private const SHIP_OPERATION = 'the shipment request';

    private const VOID_OPERATION = 'the cancellation';

    /** Packaging of the shipper's own, not a FedEx box. */
    private const PACKAGING_TYPE_YOUR_PACKAGING = 'YOUR_PACKAGING';

    /** The label comes back as bytes rather than a link that expires in twelve hours. */
    private const LABEL_RESPONSE_LABEL = 'LABEL';

    /** Four by six inches on plain paper, which any printer takes. */
    private const LABEL_STOCK_PAPER = 'PAPER_4X6';

    /** The shipment is billed to the merchant's own account, the one the labels are issued against. */
    private const PAYMENT_SENDER = 'SENDER';

    /** Cancelling a shipment cancels every package of it, not just the first. */
    private const DELETE_ALL_PACKAGES = 'DELETE_ALL_PACKAGES';

    /** @var array<string, string> FedEx pickup types */
    private const PICKUP_TYPES = [
        CarrierCredentialsInterface::PICKUP_TYPE_SCHEDULED => 'USE_SCHEDULED_PICKUP',
        CarrierCredentialsInterface::PICKUP_TYPE_DROP_OFF => 'DROPOFF_AT_FEDEX_LOCATION',
        CarrierCredentialsInterface::PICKUP_TYPE_ON_DEMAND => 'CONTACT_FEDEX_TO_SCHEDULE',
    ];

    public function __construct(
        private CredentialsProvider $credentialsProvider,
        private FedexConnectorFactory $connectorFactory,
        private LabelFormats $labelFormats,
        private FedexErrorTranslator $errorTranslator = new FedexErrorTranslator(),
    ) {
    }

    public function ship(ShipmentRequest $request): ShipmentResult
    {
        try {
            $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_FEDEX);
            $accountNumber = $credentials->getCredentials()[CarrierCredentialsInterface::ACCOUNT_NUMBER]
                ?? throw new CarrierCredentialsException('FedEx does not issue labels without an account number.');
            $pickupType = self::PICKUP_TYPES[(string) $credentials->getPickupType()]
                ?? throw new CarrierCredentialsException(sprintf('"%s" is not a pickup type FedEx knows.', (string) $credentials->getPickupType()));

            foreach ([$request->origin, $request->destination] as $address) {
                if (!$address->hasContact()) {
                    throw new CarrierRejectedRequestException('FedEx does not print a label without a name and a phone at both ends.');
                }
            }

            $response = $this->connectorFactory->create($credentials)->shipV1()->createShipment(new FullSchemaShip(
                requestedShipment: new RequestedShipment(
                    shipper: new ShipperParty($this->address($request->origin), $this->contact($request->origin)),
                    recipients: [new RecipientsParty($this->address($request->destination), $this->contact($request->destination))],
                    pickupType: $pickupType,
                    serviceType: $request->serviceCode,
                    packagingType: self::PACKAGING_TYPE_YOUR_PACKAGING,
                    shippingChargesPayment: new Payment(self::PAYMENT_SENDER),
                    labelSpecification: new LabelSpecification(
                        labelStockType: self::LABEL_STOCK_PAPER,
                        imageType: $this->labelFormats->for(CarrierCredentialsInterface::CARRIER_FEDEX),
                    ),
                    requestedPackageLineItems: array_map($this->package(...), $request->packages),
                ),
                labelResponseOptions: self::LABEL_RESPONSE_LABEL,
                accountNumber: new ShipperAccountNumber($accountNumber),
            ));

            $shipment = $response->dto();
            if (!$shipment instanceof ShpcResponseVoShipShipment) {
                throw new UnexpectedCarrierResponseException('FedEx answered the shipment request without a shipment reply.');
            }

            return $this->readResult($shipment, $request);
        } catch (\Throwable $exception) {
            throw $this->errorTranslator->translate($exception, self::SHIP_OPERATION);
        }
    }

    public function void(string $carrierReference): VoidResult
    {
        try {
            $credentials = $this->credentialsProvider->get(CarrierCredentialsInterface::CARRIER_FEDEX);
            $accountNumber = $credentials->getCredentials()[CarrierCredentialsInterface::ACCOUNT_NUMBER]
                ?? throw new CarrierCredentialsException('FedEx does not cancel a shipment without an account number.');

            $response = $this->connectorFactory->create($credentials)->shipV1()->cancelShipment(new FullSchemaCancelShipment(
                accountNumber: new ShipperAccountNumber($accountNumber),
                trackingNumber: $carrierReference,
                deletionControl: self::DELETE_ALL_PACKAGES,
            ));

            // Reading the answer is inside the try as well: an unreadable body throws a JsonException, and
            // that is FedEx being unintelligible, not the plugin being broken.
            $cancellation = $response->dto();
            if (!$cancellation instanceof ShpcResponseVoCancelShipment) {
                throw new UnexpectedCarrierResponseException('FedEx answered the cancellation without a cancellation reply.');
            }
        } catch (\Throwable $exception) {
            $translated = $this->errorTranslator->translate($exception, self::VOID_OPERATION);

            // FedEx saying «no» is an answer, not a failure of the plugin: the label stays issued, and the
            // warehouse has to be told that rather than left thinking the parcel was cancelled.
            if ($translated instanceof CarrierRejectedRequestException) {
                return VoidResult::refused($translated->getMessage());
            }

            throw $translated;
        }

        if (true !== $cancellation->output?->cancelledShipment) {
            return VoidResult::refused(sprintf(
                'FedEx did not cancel the shipment: %s',
                '' === (string) $cancellation->output?->message ? 'it gave no reason.' : (string) $cancellation->output?->message,
            ));
        }

        return VoidResult::voided();
    }

    /**
     * FedEx has no operation that answers «did you issue this?».
     *
     * Its two enquiries are `getConfirmedShipmentAsyncResults`, which reads back an asynchronous job by its
     * identifier, and `shipmentPackageValidate`, which checks a request before it is sent. Neither says
     * whether a shipment exists, so the ambiguity of a shipment nobody got an answer for needs a person to
     * look in FedEx's own portal. Null is how that is said.
     */
    public function recover(string $ownReference): ?ShipmentResult
    {
        return null;
    }

    private function address(Address $address): PartyAddress
    {
        return new PartyAddress(
            streetLines: [$address->street],
            city: $address->city,
            stateOrProvinceCode: $address->provinceCode,
            postalCode: $address->postcode,
            countryCode: $address->countryCode,
            residential: $address->residential,
        );
    }

    private function contact(Address $address): PartyContact
    {
        return new PartyContact(
            personName: $address->contactName,
            phoneNumber: $address->phone,
            companyName: $address->companyName,
        );
    }

    private function package(ShipmentPackage $shipmentPackage): RequestedPackageLineItem
    {
        $package = $shipmentPackage->package;

        // FedEx wants the longest side as the length.
        $sides = [$package->length, $package->width, $package->height];
        rsort($sides);

        return new RequestedPackageLineItem(
            weight: new Weight(
                units: match ($package->weightUnit) {
                    CarrierShippingOriginInterface::WEIGHT_UNIT_KG => 'KG',
                    default => 'LB',
                },
                // Rounding up keeps the declared package from ever being lighter than the real one.
                value: ceil(round($package->weight * 10, 6)) / 10,
            ),
            dimensions: new Dimensions(
                length: (int) ceil(round($sides[0], 6)),
                width: (int) ceil(round($sides[1], 6)),
                height: (int) ceil(round($sides[2], 6)),
                units: match ($package->dimensionUnit) {
                    CarrierShippingOriginInterface::DIMENSION_UNIT_CM => 'CM',
                    default => 'IN',
                },
            ),
        );
    }

    private function readResult(ShpcResponseVoShipShipment $response, ShipmentRequest $request): ShipmentResult
    {
        $shipment = $response->output?->transactionShipments[0] ?? null;
        $reference = (string) ($shipment?->masterTrackingNumber ?? '');
        $pieces = $shipment?->pieceResponses ?? [];

        if ('' === $reference || [] === $pieces) {
            throw new UnexpectedCarrierResponseException('FedEx answered the shipment request without a master tracking number or without any package.');
        }

        // One label per package, or the plugin would store fewer labels than there are parcels to put them on.
        if (\count($pieces) !== \count($request->packages)) {
            throw new UnexpectedCarrierResponseException(sprintf(
                'FedEx answered with %d label(s) for a shipment of %d package(s).',
                \count($pieces),
                \count($request->packages),
            ));
        }

        $labels = [];
        foreach (array_values($pieces) as $position => $piece) {
            $document = $piece->packageDocuments[0] ?? null;
            $contents = null === $document ? false : base64_decode((string) $document->encodedLabel, true);
            if (false === $contents || '' === $contents) {
                throw new UnexpectedCarrierResponseException('FedEx answered the shipment request with a package that carries no readable label.');
            }

            $labels[] = new IssuedLabel(
                $position,
                (string) $piece->trackingNumber,
                (string) ($document?->docType ?? ''),
                $contents,
            );
        }

        return new ShipmentResult($reference, $labels);
    }
}
