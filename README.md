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

## Setting up the store

### 1. Carrier credentials

**Configuration → Carrier credentials.** One entry per carrier, with its environment (sandbox or
production), how packages reach it — a scheduled pickup, dropped off at a carrier location, or a
pickup requested when needed — the client id and secret of the carrier's API application, and the
account number. The secret is never shown again; leaving it empty on an edit keeps the current one.

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
