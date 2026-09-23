# Installation

## Before you start

### What your application needs

- PHP 8.2 or newer
- Sylius 2.2 or newer

Nothing else. Rates and shipment statuses are kept on the filesystem, so the plugin runs on an
installation with no Redis and no service beyond the ones Sylius itself needs.

### What you need from the carriers

This is the part that is not in your hands, so it is worth starting early:

| What | What it is for | How long |
|---|---|---|
| A UPS developer account, with a client id and secret | Rates, labels and tracking | Same day, online |
| A FedEx developer account, with a client id and secret | The same, for FedEx | Same day, online |
| **FedEx label certification** | **Issuing real labels with FedEx in production** | Weeks. It is a review with deadlines of their own |

Rates and tracking work against each carrier's sandbox from the first day. **Issuing labels in
production with FedEx does not**: FedEx reviews the labels a plugin generates before allowing it,
and no amount of code shortens that. Open it before you need it.

Each set of credentials also carries the account number the carrier bills to. Without it you can
still be quoted, but you cannot issue a label.

## Install it

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

### 3. Import the configuration and the routes

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
Carrier credentials — one to **Catalog** — Variants missing customs data — and, in the shop API,
`PUT /api/v2/shop/orders/{tokenValue}/destination-type`.

### 4. Generate the key that encrypts the carrier credentials

A carrier's credentials are kept encrypted, with a key of their own, separate from Sylius's payment
key. Its path comes from `JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH`, which defaults to
`%kernel.project_dir%/config/encryption/jpmmartin_carrier.key`:

```bash
# .env.local
JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH='%kernel.project_dir%/config/encryption/jpmmartin_carrier.key'
```

```bash
bin/console jpmmartin:carrier:generate-key
```

The command refuses to touch a key that already exists unless given `--overwrite`: replacing the key
leaves every credential already stored unreadable, and they have to be typed again. Keep the file
out of your repository and back it up with the rest of your secrets.

### 5. Run the migrations

```bash
bin/console doctrine:migrations:migrate
```

## What comes next

Nothing is offered to a buyer yet: the plugin has no credentials, no address to ship from and no
shipping method using it.

- [Configuration](configuration.md) — the settings, and where the plugin keeps its files.
- [Usage](usage.md) — setting the store up and despatching an order.
