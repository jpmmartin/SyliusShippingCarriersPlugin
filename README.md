<p align="center">
    <a href="https://sylius.com" target="_blank">
        <picture>
          <source media="(prefers-color-scheme: dark)" srcset="https://media.sylius.com/sylius-logo-800-dark.png">
          <source media="(prefers-color-scheme: light)" srcset="https://media.sylius.com/sylius-logo-800.png">
          <img alt="Sylius Logo." src="https://media.sylius.com/sylius-logo-800.png">
        </picture>
    </a>
</p>

<h1 align="center">Shipping Carriers Plugin</h1>

<p align="center">UPS and FedEx shipping for Sylius: real-time rates, labels, tracking and customs.</p>

The buyer sees what UPS or FedEx charge for their own cart, and is charged that. When a carrier does
not answer, the shipping method either hides or falls back to a flat amount the store sets, so the
checkout keeps working and no shipment goes out uncharged. Once an order ships, its tracking shows
in the buyer's account.

From the order itself, an operator issues the labels of a shipment, one per package, and cancels
them with the carrier when a parcel is not going out. A shipment that crosses a border is declared
to customs in the same operation, and the paperwork comes back with the labels.

> **Issuing a label contracts a shipment and is billed.** Everything up to this point only reads
> from the carriers. From here on the plugin spends the merchant's money, which is why nothing is
> ever issued as a side effect: an operator asks for it, from a page, every time.

## Requirements

- PHP 8.2 or newer
- Sylius 2.2 or newer

Nothing else. Rates and tracking are kept on the filesystem, so the plugin runs on an installation
with no Redis and no service outside the ones Sylius itself needs.

## Installation

### 1. Require the package

```bash
composer require jpmmartin/sylius-shipping-carriers-plugin
```

### 2. Register the bundle

```php
# config/bundles.php

return [
    // ...
    JpmMartin\SyliusShippingCarriersPlugin\JpmMartinSyliusShippingCarriersPlugin::class => ['all' => true],
];
```

### 3. Import the plugin's configuration and routes

```yaml
# config/packages/jpmmartin_shipping_carriers.yaml

imports:
    - { resource: "@JpmMartinSyliusShippingCarriersPlugin/config/config.yaml" }
```

```yaml
# config/routes/jpmmartin_shipping_carriers.yaml

jpmmartin_carrier_admin:
    resource: "@JpmMartinSyliusShippingCarriersPlugin/config/routes/admin.yaml"
    prefix: '/%sylius_admin.path_name%'
```

That adds three entries to the admin's **Configuration** menu — Shipping origins, Package boxes and
Carrier credentials — and, in the shop API, `PUT /api/v2/shop/orders/{tokenValue}/destination-type`.

### 4. Generate the key that encrypts the carrier credentials

The credentials of a carrier are kept encrypted, with a key of their own, separate from Sylius's
payment key. Its path comes from `JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH`, which defaults to
`%kernel.project_dir%/config/encryption/jpmmartin_carrier.key`:

```bash
# .env.local
JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH='%kernel.project_dir%/config/encryption/jpmmartin_carrier.key'
```

```bash
bin/console jpmmartin:carrier:generate-key
```

The command refuses to touch a key that already exists unless it is given `--overwrite`: replacing
the key leaves every credential already stored unreadable. Keep the file out of the repository and
back it up with the rest of your secrets.

### 5. Run the migrations

```bash
bin/console doctrine:migrations:migrate
```

## Configuration

Everything has a default; an application only writes the keys it wants to change.

```yaml
# config/packages/jpmmartin_shipping_carriers.yaml

jpm_martin_sylius_shipping_carriers:
    # Seconds a request to a carrier may take before it counts as a failure of the carrier.
    carrier_timeout: 10.0

    # Seconds a rate the carrier gave is quoted for before the carrier is asked again.
    rate_lifetime: 900

    # Seconds that rate is still kept afterwards as the last known rate, charged when the carrier
    # fails. Never less than rate_lifetime.
    rate_retention: 86400

    # Seconds the status of a shipment is kept before the carrier is asked again.
    tracking_lifetime: 300

    # Where the labels and the customs documents are kept. Outside the published directory on
    # purpose: see below.
    documents_dir: '%kernel.project_dir%/var/jpmmartin_carrier/documents'

    # Seconds a label or a customs document is kept before the purge deletes the file. The default
    # is 180 days: the longest a UPS shipment can be cancelled for at all, so nothing is ever
    # deleted while it could still be sent back.
    documents_retention: 15552000

    # Seconds a document still waiting to be named by a row is left alone before the purge collects
    # it. Issuing takes seconds, so a day is far more than enough.
    temporary_documents_retention: 86400

    # What to ask each carrier to print its labels as. The two share no format.
    # UPS: GIF, ZPL, EPL or SPL. FedEx: PDF or ZPLII.
    label_formats:
        ups: 'GIF'
        fedex: 'PDF'

    # The services an administrator can choose for a shipping method, as the carrier's own service
    # code and the name shown for it. What is written here is added to the list the plugin ships
    # with, or renames an entry of it.
    services:
        ups:
            '02': 'UPS 2nd Day Air'
        fedex:
            FEDEX_EXPRESS_SAVER: 'FedEx Express Saver'
```

The plugin ships with UPS `01` and `03`, and FedEx `FEDEX_GROUND`, `PRIORITY_OVERNIGHT` and
`STANDARD_OVERNIGHT`. Carriers publish many more, and their codes differ by account, so add the ones
your account sells.

### Where the rates and the statuses are kept

Both are ordinary Symfony cache pools, declared with the filesystem adapter so that the plugin works
on a plain installation. An application points them somewhere else from its own configuration,
without touching the plugin:

```yaml
# config/packages/cache.yaml

framework:
    cache:
        pools:
            jpmmartin_carrier.cache.rates:
                adapter: cache.adapter.redis
            jpmmartin_carrier.cache.tracking:
                adapter: cache.adapter.redis
```

### Where the labels and the customs documents are kept

**Not under the published directory, and never served as a static file.** A label lets whoever
holds it send a parcel on the merchant's account, and both a label and a customs invoice carry the
buyer's address.

The store is an ordinary Flysystem storage the plugin declares with the local adapter and private
visibility, pointed at `documents_dir`. An application points it somewhere else — S3, a mounted
volume — from its own configuration, without touching the plugin:

```yaml
# config/packages/flysystem.yaml

flysystem:
    storages:
        jpmmartin_carrier.storage.documents:
            adapter: 'aws'
            options:
                client: 'aws_client_service'
                bucket: 'carrier-documents'
```

Any adapter `league/flysystem-bundle` supports will do; each one needs its own package, which the
bundle names if it is missing. Only the local adapter comes with this plugin, because it is the one
every installation already has.

Whatever it is pointed at, a document is only ever handed over by an admin route that first asks
who is asking and what happened to the shipment. There is no route that takes a path.

## Setting up the store

### 1. Carrier credentials

**Configuration → Carrier credentials.** One entry per carrier, with its environment (sandbox or
production), how packages reach it — a scheduled pickup, dropped off at a carrier location, or a
pickup requested when needed — the client id and secret of the carrier's API application, and the
account number. The secret is never shown again; leaving it empty on an edit keeps the current one.

Also **who pays the duties and taxes** of an international shipment at its destination: the
recipient, which is the default and what a checkout that charged none implies, or the store, which
bills them to the account above. Credentials saved before this existed keep the recipient.

### 2. Shipping origin

**Configuration → Shipping origins.** One per channel: the address packages leave from, the units
the store's weights and dimensions are written in, the maximum weight of a package, the boxes this
origin uses, and whether a delivery counts as a home or a business when the buyer has not said.

A channel with no shipping origin offers no carrier shipping method at all, and says so in the log.

### 3. Package boxes

**Configuration → Package boxes.** The boxes the store actually ships in, with their inner and outer
measurements, their empty weight and their maximum weight. They are read in the units declared in
the shipping origin.

> **Without a box catalogue the store is quoted more than it should be.** With no box — or when the
> origin has none that fits — the plugin falls back to declaring the units stacked on their shortest
> side: as long as the longest of them, as wide as the widest, and as tall as all of them together,
> split into several packages if they go over the maximum weight. That is never smaller than what
> really ships, so the error is always in the same direction: paying for more than you send.
> Carriers bill the greater of the real and the dimensional weight, so the difference is real money.
> Fill the catalogue.

### 4. Shipping methods

**Configuration → Shipping methods**, as always in Sylius. Choose **UPS rates** or **FedEx rates**
as the calculator, then the service, and what the method does when the carrier does not answer:

- **Hide this shipping method** — it is not offered, not assigned by default, and an order cannot be
  completed with it.
- **Offer it at a flat amount** — the amount is set per channel and is charged only when there is no
  rate and no last known rate to fall back on.

### 5. Customs data

**On each product variant**, under its own section: the HS code — six to ten digits, the code
customs classifies the article by — and the country it was made in. Neither is needed to sell, and
neither is needed to ship inside one country, but **without both, an international shipment is not
issued at all**: the plugin refuses it and says which variant it could not declare, rather than
letting the parcel be stopped at a border where somebody has to pay for it.

A catalogue of thousands cannot be checked one by one, so **Catalog → Variants missing customs
data** lists exactly the ones that are incomplete: no customs data, or customs data with a field
left empty.

## Shipping an order

### Issuing the labels

On the order, on each shipment sent by a carrier of this plugin, an action issues its labels: **one
per package**, from the packages stored when the order was confirmed, not from packing it again.
The shipment keeps the carrier's tracking number without anybody typing it, and the labels are
there to download from the same place.

Several orders at once: on **Sales → Shipments**, pick them and use the batch action. A shipment
that fails does not stop the rest, and the summary says how many went out and why each one did not.

A shipment that already has its labels is never issued again in silence: to reissue it, cancel it
first.

### When the carrier does not answer

A carrier that refuses says why, and the shipment can be fixed and sent again. A carrier that
answers nothing usable — a timeout, a body that cannot be read — is different: it may well have
issued and billed the labels. That shipment is marked as **needing a check** and nothing, not the
batch and not a retry, sends it again on its own. Somebody looks at the carrier's own records and
decides.

### Cancelling them

Cancelling tells the carrier, and only counts as done when the carrier says so. A carrier that
refuses leaves the labels issued — and still billed — with the reason on the screen, not only in a
message that flashed by.

**The two windows are not alike, and the screen says which one this is:**

| Carrier | The plugin cancels it | Afterwards |
|---|---|---|
| UPS | 90 days from issuing | Between 90 and 180 days, only by contacting UPS. After that, nobody |
| FedEx | 12 hours, on the ship date printed on the label or before | Nothing |

Twelve hours against ninety days: an operator used to one finds out too late on the other.

### The customs document

A shipment that crosses a border is declared in the same request that issues the labels, and the
document the carrier prints — the commercial invoice — comes back with them and is kept beside
them, to download from the order. It goes when the labels go: a cancelled shipment offers neither.

## Keeping the files no longer than needed

A label and a customs invoice hold an address and what somebody bought. They are kept because a
shipment can still be cancelled and because the merchant may still have to prove what was sent,
and neither reason lasts forever.

Nothing is deleted on its own. A command deletes what is past the retention, meant for cron:

```bash
bin/console jpmmartin:carrier:purge-documents
```

It deletes the file and keeps the row: who issued what and when is still there to read afterwards,
without keeping the document itself. It also collects what an issue left behind when something went
wrong between writing the file and recording it — files no row names, which nothing else would ever
come looking for.

Running it twice is not a problem: the second run finds nothing left. It comes back as a failure,
with the reason in the log, when a file could not be deleted, which is the one thing a cron has to
notice.

## What the buyer sees

- On the address step, a question about whether the delivery goes to a home or a business, which
  changes the rate the carriers give. Through the shop API it is a `PUT` on
  `/api/v2/shop/orders/{tokenValue}/destination-type` with `{"type": "residential"}` or
  `{"type": "commercial"}`. Left unanswered, the default of the channel's shipping origin is used.
- On the shipping step, each carrier method with the fee that carrier gave for that cart. The order
  is charged exactly the fee that was shown.
- In their account, on an order whose shipment has a tracking number, where it is: the carrier's
  status and the events it knows of, or the tracking number and a notice when the carrier cannot be
  reached.

## Developing this plugin

> There is no `Makefile` in this repository. Drive the commands below directly.

The plugin is hosted in `sylius/test-application`, which also provides the console at
`vendor/bin/console`.

```bash
composer run test-app-init        # database-reset + frontend-clear
composer run database-reset       # drop + create + migrate + load fixtures
composer run frontend-clear       # yarn install && yarn build, then assets:install
```

Configure the database in `tests/TestApplication/.env` and `tests/TestApplication/.env.test`.

Run the test application:

```bash
symfony server:ca:install
symfony server:start -d
```

### Tests and quality tools

Every tool is configured at the repository root, so the commands take no flags:

```bash
vendor/bin/phpunit                  # tests/{Unit,Integration,Functional}
vendor/bin/behat --strict           # suites from tests/Behat/Resources/suites.yml
vendor/bin/phpstan analyse          # level max over src/ and tests/
vendor/bin/ecs check                # add --fix to apply
vendor/bin/rector process --dry-run # drop --dry-run to apply
vendor/bin/console lint:container
```

Behat's JavaScript scenarios additionally need headless Chrome on port 9222 and a running server:

```bash
google-chrome-stable --headless --remote-debugging-port=9222 --no-sandbox --window-size=2880,1800 http://127.0.0.1
APP_ENV=test symfony server:start --port=8080 --daemon
vendor/bin/behat --strict --tags="@javascript"
```

### Docker

```bash
cp compose.override.dist.yml compose.override.yml   # edit APP_SECRET before use
docker compose up -d
docker compose exec php sh
docker compose down
```

## Licence

This plugin is released under the MIT Licence. See [LICENSE](LICENSE).
