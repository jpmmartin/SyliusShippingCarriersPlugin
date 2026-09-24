# Configuration reference

Every setting of the plugin, its default, and where it keeps what it stores. The README's install
steps come first.

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

## Settings from environment variables

Every setting takes an environment variable, the Symfony way, so each server can have its own value
without a change to the configuration. A number needs a processor of its type: `int:` for the whole
seconds, `float:` for `carrier_timeout`, or a processor of the store's own that declares the type.

```yaml
# config/packages/jpmmartin_shipping_carriers.yaml

jpm_martin_sylius_shipping_carriers:
    carrier_timeout: '%env(float:CARRIER_TIMEOUT)%'
    rate_lifetime: '%env(int:CARRIER_RATE_LIFETIME)%'
    rate_retention: '%env(int:CARRIER_RATE_RETENTION)%'
    tracking_lifetime: '%env(int:CARRIER_TRACKING_LIFETIME)%'
    documents_retention: '%env(days:CARRIER_DOCUMENTS_RETENTION_DAYS)%'
    temporary_documents_retention: '%env(int:CARRIER_TEMPORARY_DOCUMENTS_RETENTION)%'
```

```dotenv
# .env

CARRIER_TIMEOUT=10
CARRIER_RATE_LIFETIME=900
CARRIER_RATE_RETENTION=86400
CARRIER_TRACKING_LIFETIME=300
CARRIER_DOCUMENTS_RETENTION_DAYS=180
CARRIER_TEMPORARY_DOCUMENTS_RETENTION=86400
```

`days:` above is not Symfony's: it is a processor a store writes for itself, and the plugin takes it
because it declares that what it hands over is an `int`:

```php
// src/EnvVarProcessor/DaysEnvVarProcessor.php

final class DaysEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): int
    {
        return (int) $getEnv($name) * 24 * 60 * 60;
    }

    public static function getProvidedTypes(): array
    {
        return ['days' => 'int'];
    }
}
```

`documents_dir` and the two label formats are text, so they take a variable as it is:
`'%env(CARRIER_DOCUMENTS_DIR)%'`.

**What a variable cannot do.**

- **A number without a processor.** A variable without one is text, and the container refuses to
  compile a number setting given text, naming the setting.
- **The whole list of `services`.** Symfony takes no variable for a list. Each name in it can be
  one: `'02': '%env(CARRIER_UPS_02_NAME)%'`.

**What is checked, and when.** A value written in the file is checked when the container compiles:
a timeout under 0.1 seconds, any other setting under 1 second, a `rate_retention` under the
`rate_lifetime`, or a format the carrier does not print, and the container refuses to compile. A
value from a variable is only known when the store runs, so it is checked where it is used, and never
applied if it would have been refused written:

- **Rates.** No carrier is asked, and the plugin's shipping methods are not offered.
- **Tracking.** The buyer sees that the status is not available.
- **Labels.** Issuing and cancelling refuse before anything is recorded or sent, and the admin says why.
- **The purge.** It deletes nothing, and the command fails.

Every case is logged as an error naming the setting and its value.

## Settings per channel

A channel can say, on its shipping origin, what it wants in place of the configuration:

- **`carrier_timeout`**: how long a carrier is waited for.
- **`rate_lifetime` and `rate_retention`**: how long a rate is quoted for, and kept as the last known
  rate.
- **`tracking_lifetime`**: how long the status of a shipment is kept.
- **`documents_retention`**: how long labels and customs documents are kept.
- **Label formats**: what UPS and FedEx print labels as.
- **Services**: the services the channel adds to each carrier's list.

**Where:** **Configuration → Shipping origins**, on the channel's origin.

**What applies.** For each order, what its channel says, and for whatever the channel leaves empty
the configuration's, written or from a variable. Each field shows under it the value that applies
when it is left empty.

**Checked when saved.** A channel's values are held to the same limits as the configuration's: a
carrier waited for at least 0.1 seconds, every other setting at least one second, a retention of
rates no shorter than the lifetime the channel will have (its own or the configuration's), and only
a format the carrier prints. Nothing refused is saved.

**What stays the whole store's.** Two settings stay in the configuration only:

- **`documents_dir`**, because the storage is built before any channel is known.
- **`temporary_documents_retention`**, because the files it applies to belong to no order, and so
  to no channel.

**Services per channel.** A channel writes its own one a line, as `CODE = Name`. Its list is the
configuration's plus those: they add services, or rename one. A shipping method may be offered in
several channels, so it is chosen from every list, and saving one whose service is missing from one
of its channels is refused, naming that channel. The configuration's services are in every
channel's list.

**Rates are kept per channel.** Two channels quoting the same cart each ask the carrier once. A
channel that kept rates for less would otherwise drop the last known rate of one that keeps it for
longer.

## Where the rates and the statuses are kept

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

## Where the labels and the customs documents are kept

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
