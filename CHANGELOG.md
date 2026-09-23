# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). What a version number promises
here, and what counts as a breaking change, are written down in [RELEASING.md](RELEASING.md).

## [Unreleased]

What the first release will carry.

### Added

- **Rates at checkout from UPS and FedEx.** The carrier is asked once for every service of a
  shipment, the answer is cached for `rate_lifetime`, and the buyer is charged exactly the fee they
  were shown.
- **What a shipping method does when its carrier does not answer**, chosen per method: hide it, or
  charge a flat amount set per channel. The last rate the carrier gave for that cart is kept for
  `rate_retention` and charged before either.
- **Home or business delivery**, which changes what the carriers charge. The buyer is asked on the
  address step, or through the shop API with `PUT /api/v2/shop/orders/{tokenValue}/destination-type`;
  unanswered, the default of the channel's shipping origin is used.
- **A shipping origin per channel**: the address packages leave from, the units the catalogue is
  measured in, the heaviest package allowed and the boxes that origin uses.
- **A catalogue of package boxes**, and the packages of an order stored when it is confirmed, so the
  labels are issued for what was quoted. With no box that fits, the units are declared stacked on
  their shortest side, which never under-declares.
- **Labels, one per package**, issued from the order or in a batch from the shipments grid,
  downloaded from the admin and never issued twice in silence. The carrier's tracking number is
  stored on the shipment. A shipment the carrier answered nothing usable about is marked as needing
  a check, and nothing sends it again on its own.
- **Cancelling labels with the carrier that issued them**, counted as done only when the carrier
  says so. The order screen says which window applies: 90 days with UPS, 12 hours with FedEx.
- **Customs.** An HS code and a country of origin per variant, a list under *Catalog → Variants
  missing customs data* of the ones that lack them, the commercial invoice issued in the same request
  as the labels, and who pays duties and taxes at destination, per carrier. An international shipment
  whose variants cannot be declared is refused, naming the variant, rather than issued.
- **Tracking in the buyer's account**: the carrier's status and events for an order whose shipment
  has a tracking number, cached for `tracking_lifetime`.
- **Retention.** Labels and customs documents are deleted after `documents_retention` — 180 days by
  default — by `jpmmartin:carrier:purge-documents`, meant for cron. The file goes; the record of who
  issued what stays.
- **Documents kept out of the published directory**, in a Flysystem storage of their own with private
  visibility, and handed over only by admin routes that check the shipment first. No route takes a
  path.
- **Carrier credentials encrypted at rest** with a key of their own, separate from Sylius's payment
  key, created by `jpmmartin:carrier:generate-key`.
- **Nothing beyond what Sylius needs.** Rates and statuses live in cache pools on the filesystem by
  default, which an application can point at Redis from its own configuration.
- **Migrations written against Doctrine's schema representation** rather than an engine's SQL, so the
  same set runs on MySQL, MariaDB and PostgreSQL.
- English translations.

### Known limitations

Stated here as well as in the README, because they decide whether this release fits a store:

- **Issuing FedEx labels in production needs FedEx's label certification**, a review of the labels
  the plugin generates that takes weeks. Rates and tracking work against both sandboxes from the
  first day.
- **Only UPS and FedEx, and a third carrier cannot be added from outside yet.** The list of carriers
  is closed in the configuration, the credentials form and its validation; see
  [docs/extending.md](docs/extending.md).
- No return labels, scheduled pickups or insurance; duties and taxes are not estimated for the buyer
  before they pay; labels are handed over as files, not sent to a printer; one shipping origin per
  shipment.

[Unreleased]: https://github.com/jpmmartin/SyliusShippingCarriersPlugin/commits/main
