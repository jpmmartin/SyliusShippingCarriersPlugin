<h1 align="center">Sylius Shipping Carriers Plugin</h1>

<p align="center">UPS and FedEx for Sylius: real rates at checkout, labels from the admin, customs paperwork and tracking.</p>

<p align="center">
    <a href="LICENSE"><img alt="Licence: MIT" src="https://img.shields.io/badge/licence-MIT-blue.svg"></a>
    <img alt="PHP 8.2+" src="https://img.shields.io/badge/php-8.2%2B-777bb3.svg">
    <img alt="Sylius 2.2+" src="https://img.shields.io/badge/sylius-2.2%2B-1abb9c.svg">
</p>

The buyer is quoted what UPS or FedEx charge for their own cart, and is charged that. From the order
itself, an operator issues the labels of a shipment — one per package — and cancels them with the
carrier when a parcel is not going out. A shipment that crosses a border is declared to customs in
the same operation, and the paperwork comes back with the labels.

When a carrier does not answer, the shipping method either hides or falls back to a flat amount the
store sets, so the checkout keeps working and no shipment goes out uncharged.

## Compatibility

| | |
|---|---|
| PHP | 8.2 or newer |
| Sylius | 2.2 or newer |

Rates and shipment statuses are kept on the filesystem, so the plugin runs on an installation with
no Redis and no service beyond the ones Sylius itself needs.

## What it covers

- **Rates at checkout.** One call per carrier for every service of a shipment, cached, with the
  buyer charged exactly the amount that was shown.
- **What happens when a carrier is down.** Per shipping method: hide it, or charge a flat amount set
  per channel. The last known rate is used before either.
- **Packing.** A catalogue of boxes, the packages stored when the order is confirmed, and the
  labels issued against those same packages.
- **Labels.** Issued from the order or in a batch from the shipments grid, downloaded from the
  admin, cancelled with the carrier that issued them.
- **Customs.** HS code and country of origin per variant, the commercial invoice issued with the
  labels, and who pays duties and taxes at destination.
- **Tracking.** What the carrier says about a shipment, shown to the buyer in their account.
- **Retention.** Labels and customs documents are kept for as long as you set, then deleted by a
  command you schedule — the file goes, the record stays.

## What it does not cover

- Return labels and reverse logistics.
- Scheduled pickups, insurance and value-added services.
- Estimating duties and taxes for the buyer before they pay.
- Printing straight to a thermal printer. The file is handed over; printing it is your system's job.
- Consolidating several orders into one shipment.
- Carriers other than UPS and FedEx.
- More than one shipping origin per shipment (multi-warehouse).
- Changes to Sylius's own state machines, or new fields on its entities.

## What you need from the carriers

Not in your hands, so worth starting early:

- A **UPS developer account** and a **FedEx developer account**, each with a client id, a secret and
  the account number they bill to.
- **FedEx label certification** to issue real labels in production. FedEx reviews the labels a
  plugin generates before allowing it, and that takes weeks. Rates and tracking work against each
  sandbox from the first day; issuing in production with FedEx does not.

## Installation

```bash
composer require jpmmartin/sylius-shipping-carriers-plugin
```

Then register the bundle, import the configuration and the routes, generate the key that encrypts
the carrier credentials, and run the migrations. Each step is in
**[docs/installation.md](docs/installation.md)**.

## Documentation

- **[Installation](docs/installation.md)** — requirements, the five steps, and what the carriers ask
  of you.
- **[Configuration](docs/configuration.md)** — every setting, and where the plugin keeps its files.
- **[Usage](docs/usage.md)** — setting the store up, despatching an order, cancelling, and the
  retention.

## Licence

MIT. See [LICENSE](LICENSE).
