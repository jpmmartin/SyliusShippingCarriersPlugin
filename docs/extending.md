# Extending the plugin

This page names every seam a store, a theme or another plugin may rely on. **What is named here is
the plugin's public surface**, and the versioning promise in [upgrading.md](upgrading.md) is made
about it: a change to any of it is a breaking change and ships as a major release. Every class not
named here carries `@internal` in the code and may change in a minor release. A test holds both
halves to the code — every name below exists, and every class under `src/` is either named below or
marked internal — so this page cannot quietly go stale.

The seams are the ones Sylius and Symfony already give you: Twig hooks for what is shown, decorated
services for what is done, Sylius resources for what is stored, and routes, the API and the console
for integrating. Nothing here needs a fork.

## What is shown: hooks, hookables and templates

Every piece the plugin adds to a Sylius screen is rendered through Twig hooks. Override a hookable's
template, change its priority, add one of your own or disable one in your store's
`sylius_twig_hooks` configuration, the way the Sylius documentation describes.

| Hook | Hookables |
|---|---|
| `sylius_admin.product_variant.create.content.form.side_navigation` | `jpmmartin_carrier_customs_data` |
| `sylius_admin.product_variant.update.content.form.side_navigation` | `jpmmartin_carrier_customs_data` |
| `sylius_admin.product_variant.create.content.form.sections` | `jpmmartin_carrier_customs_data` |
| `sylius_admin.product_variant.update.content.form.sections` | `jpmmartin_carrier_customs_data` |
| `sylius_admin.product_variant.create.content.form.sections.jpmmartin_carrier_customs_data` | `header`, `body` |
| `sylius_admin.product_variant.update.content.form.sections.jpmmartin_carrier_customs_data` | `header`, `body` |
| `sylius_admin.order.show.content.sections.shipments.item.actions` | `jpmmartin_carrier_issue_labels`, the label actions of a shipment |
| `jpmmartin_carrier_admin.incomplete_customs_data.index.content.header.title_block` | `title` |
| `sylius_shop.checkout.address.content.form` | `destination_type`, *Where are we delivering?* |
| `sylius_shop.account.order.show.content.main.summary` | `carrier_tracking`, where the buyer's shipments are |

Every template under the plugin's `templates/` directory can be overridden by path, under your
store's `templates/bundles/JpmMartinSyliusShippingCarriersPlugin/`. **The path is the promise; what a
template receives is not.** The variables and Twig functions the plugin's templates use are internal,
so an override is a copy you follow on upgrade.

## What is done: services you may decorate

Each of these is reachable by its interface — autowire it, or decorate it by the service id it is
aliased to. The implementation behind each is internal.

| Interface | Service | What it decides |
|---|---|---|
| `JpmMartin\SyliusShippingCarriersPlugin\Rate\RateProviderInterface` | `jpmmartin_carrier.rate_provider` | the rate of one service for a shipment, and whether the carrier failed |
| `JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingProviderInterface` | `jpmmartin_carrier.tracking_provider` | what the carrier says about a shipment |
| `JpmMartin\SyliusShippingCarriersPlugin\Packaging\PackagingStrategyInterface` | `jpmmartin_carrier.packaging_strategy` | how a shipment's units go into packages — see below |
| `JpmMartin\SyliusShippingCarriersPlugin\Label\LabelIssuerInterface` | `jpmmartin_carrier.label.issuer` | issuing the labels of a shipment, and settling one nobody knows was issued |
| `JpmMartin\SyliusShippingCarriersPlugin\Label\BatchIssuerInterface` | `jpmmartin_carrier.label.batch_issuer` | issuing several shipments, one failure not stopping the rest |
| `JpmMartin\SyliusShippingCarriersPlugin\Label\LabelVoiderInterface` | `jpmmartin_carrier.label.voider` | cancelling issued labels with the carrier |
| `JpmMartin\SyliusShippingCarriersPlugin\Unit\StoreUnitsResolverInterface` | `jpmmartin_carrier.resolver.store_units` | the units the store's weights and dimensions are written in |
| `JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationTypeResolverInterface` | `jpmmartin_carrier.resolver.destination_type` | whether an order goes to a home or a business |
| `JpmMartin\SyliusShippingCarriersPlugin\Encryption\EncrypterInterface` | `jpmmartin_carrier.encrypter` | how the carrier credentials are encrypted at rest |

The label services answer with a `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface`,
the record of what happened to a shipment's labels. They throw
`JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AlreadyIssuedException` for a shipment that
already has its labels, `JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\AmbiguousShipmentException`
for one nobody knows was issued, and `JpmMartin\SyliusShippingCarriersPlugin\Label\Exception\NotIssuedException`
when there is nothing to cancel.

## The carriers

Each carrier is two services: one that quotes and tracks, implementing
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\CarrierInterface`, and one that issues and cancels
labels, implementing `JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelCarrierInterface`.

| Carrier | Quotes and tracks | Issues and cancels |
|---|---|---|
| UPS | `jpmmartin_carrier.carrier.ups` | `jpmmartin_carrier.carrier.label.ups` |
| FedEx | `jpmmartin_carrier.carrier.fedex` | `jpmmartin_carrier.carrier.label.fedex` |

The plugin finds them by the DI tags `jpmmartin_carrier.carrier` and `jpmmartin_carrier.label_carrier`,
each with a `carrier` attribute holding the carrier's code — `ups` or `fedex`. Decorate one of the
four services to change what is sent to a carrier or what is made of its answer; the rest of the
plugin sees no difference.

Both interfaces speak in the plugin's own types, never in a carrier's: a
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\RateRequest` of two
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Address` and some
`JpmMartin\SyliusShippingCarriersPlugin\Packaging\Package`, answered with a
`JpmMartin\SyliusShippingCarriersPlugin\Rate\RateSet` of `JpmMartin\SyliusShippingCarriersPlugin\Rate\Rate`;
a `JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingInfo` with its
`JpmMartin\SyliusShippingCarriersPlugin\Tracking\TrackingEvent`; a
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentRequest` of
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentPackage`, with a
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsInvoice` of
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsItem` when it crosses a border, answered
with a `JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\ShipmentResult` holding each
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\IssuedLabel` and any
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\CustomsDocument`; and a
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\VoidResult` for a cancellation.

A carrier that cannot answer throws a `JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierException`:
`JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierRejectedRequestException` when it
refused, `JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierUnavailableException` when it
could not be reached, `JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\UnexpectedCarrierResponseException`
when its answer could not be read, and `JpmMartin\SyliusShippingCarriersPlugin\Carrier\Exception\CarrierCredentialsException`
when there was nothing to ask it with.

### What is not possible yet: a third carrier

**A carrier of your own cannot be added from outside the plugin today**, and this page does not
pretend otherwise. Implementing the two interfaces and tagging the services is not enough, because
the list of carriers is closed in three other places: the `services` and `label_formats` settings
accept only `ups` and `fedex`; the credentials form offers only those two and their validation
refuses any other; and the cancellation windows are known only for those two. Opening those is a
change of its own.

## Values, exceptions and constants you may use

`JpmMartin\SyliusShippingCarriersPlugin\Rate\RateResult` is what the rate provider answers — the
rate, whether the carrier failed, and the last known rate. `JpmMartin\SyliusShippingCarriersPlugin\Label\BatchResult`
is one shipment of a batch. `JpmMartin\SyliusShippingCarriersPlugin\Packaging\Exception\UnpackableShipmentException`
is what a packaging strategy throws for a shipment it cannot pack.
`JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception\EncryptionException` is what the
encrypter throws.

`JpmMartin\SyliusShippingCarriersPlugin\Destination\DestinationType` holds the two destination types,
`residential` and `commercial`, as the shop API takes them.
`JpmMartin\SyliusShippingCarriersPlugin\Shipping\FailurePolicy` holds what a shipping method does when
its carrier does not answer, `hide` and `flat`.
`JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator` holds the keys a
carrier shipping method keeps in its configuration — `service`, `failure_policy` and `flat_amount`.
The two calculators are named `ups_rate` and `fedex_rate`.

## What is stored: resources, repositories and forms

The plugin's nine resources are Sylius resources configured under
`jpm_martin_sylius_shipping_carriers.resources`, so a store extends a model the Sylius way:

| Resource | Contract | Model |
|---|---|---|
| `shipping_origin` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin` |
| `credentials` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentialsInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCredentials` |
| `package_box` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBoxInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierPackageBox` |
| `shipment_export` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExportInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentExport` |
| `shipment_label` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabelInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentLabel` |
| `customs_data` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsDataInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierCustomsData` |
| `order_destination` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestinationInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierOrderDestination` |
| `shipment_packaging` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackagingInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackaging` |
| `shipment_package` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackageInterface` | `JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShipmentPackage` |

Each has its repository at `jpmmartin_carrier.repository.` followed by the resource's name —
`jpmmartin_carrier.repository.shipping_origin`, for instance. Two have a repository of their own,
which a store's replacement extends: `JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierPackageBoxRepository`,
behind `JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierPackageBoxRepositoryInterface`, and
`JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierShipmentExportRepository`, behind
`JpmMartin\SyliusShippingCarriersPlugin\Repository\CarrierShipmentExportRepositoryInterface`.

The forms a store may extend with a form type extension are
`JpmMartin\SyliusShippingCarriersPlugin\Form\Type\CarrierShippingOriginType`,
`JpmMartin\SyliusShippingCarriersPlugin\Form\Type\CarrierCredentialsType`,
`JpmMartin\SyliusShippingCarriersPlugin\Form\Type\CarrierPackageBoxType` and
`JpmMartin\SyliusShippingCarriersPlugin\Form\Type\CarrierCustomsDataType`. The bundle class is
`JpmMartin\SyliusShippingCarriersPlugin\JpmMartinSyliusShippingCarriersPlugin`.

## Reacting: what the plugin does to Sylius

The plugin dispatches no events of its own. Besides its screens and its admin menu, it takes part in
what Sylius does in five places:

- it stores the packages of an order when the checkout completes, on
  `workflow.sylius_order_checkout.completed.complete`;
- an order processor records, in the shipping adjustment of a carrier method, whether the amount is a
  fresh rate, the last known rate or the flat amount;
- a `kernel.exception` listener keeps the total Sylius recalculated when it refuses, through the API,
  an order whose rate changed while the buyer was confirming it;
- Doctrine listeners encrypt the carrier credentials when they are saved and decrypt them when they are
  loaded;
- and Doctrine listeners delete a shipment's labels and customs document when the shipment is deleted.

React where every other listener reacts — on Sylius's own workflow events — or decorate one of the
services above. How each of those five does its work is internal.

## Integrating: routes, the API and the console

| Route | Path | What it is |
|---|---|---|
| `jpmmartin_carrier_admin_shipping_origin_index` | `/shipping-origins/` | the shipping origins, and their create, update and delete routes beside it |
| `jpmmartin_carrier_admin_package_box_index` | `/package-boxes/` | the package boxes, likewise |
| `jpmmartin_carrier_admin_credentials_index` | `/carrier-credentials/` | the carrier credentials, likewise |
| `jpmmartin_carrier_admin_incomplete_customs_data_index` | `/incomplete-customs-data` | the variants missing customs data |
| `jpmmartin_carrier_admin_shipment_issue_labels` | `/carrier-shipments/{id}/issue-labels` | issuing a shipment's labels |
| `jpmmartin_carrier_admin_shipments_issue_labels` | `/carrier-shipments/issue-labels` | issuing several |
| `jpmmartin_carrier_admin_shipment_void_labels` | `/carrier-shipments/{id}/void-labels` | cancelling them |
| `jpmmartin_carrier_admin_label_download` | `/carrier-labels/{id}` | one label |
| `jpmmartin_carrier_admin_customs_document_download` | `/carrier-customs-documents/{id}` | one customs document |
| `jpmmartin_carrier_shop_order_destination_type_put` | `/api/v2/shop/orders/{tokenValue}/destination-type` | the shop API's home-or-business answer |

The admin routes are mounted under the prefix the README's step 3 gives them.

The console commands are `jpmmartin:carrier:generate-key` and `jpmmartin:carrier:purge-documents`.

Where the plugin keeps things is a service a store may point elsewhere from its own configuration,
as [configuration.md](configuration.md) shows: the cache pools `jpmmartin_carrier.cache.rates` and
`jpmmartin_carrier.cache.tracking`, and the Flysystem storage `jpmmartin_carrier.storage.documents`.
Every setting that page lists is public too.
