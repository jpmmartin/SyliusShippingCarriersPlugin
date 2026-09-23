# Configuration

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

## Where to go next

- [Usage](usage.md) — setting the store up and despatching an order.
- [Installation](installation.md) — if you have not got this far yet.
