<h1 align="center">Sylius Shipping Carriers Plugin</h1>

<p align="center">UPS and FedEx for Sylius 2.2: real rates at checkout, labels from the admin, customs paperwork
and tracking.</p>

<p align="center"><a href="https://github.com/jpmmartin/SyliusShippingCarriersPlugin/actions/workflows/build.yaml"><img src="https://github.com/jpmmartin/SyliusShippingCarriersPlugin/actions/workflows/build.yaml/badge.svg?branch=main" alt="Build — the test suite on main, on the lowest and the latest PHP and Sylius it supports"></a> <a href="https://github.com/jpmmartin/SyliusShippingCarriersPlugin/actions/workflows/install.yaml?query=event%3Arelease"><img src="https://github.com/jpmmartin/SyliusShippingCarriersPlugin/actions/workflows/install.yaml/badge.svg?event=release" alt="Install — the last published version, installed from its own README into a store that had never seen it"></a></p>

---

## What it does

- **Rates at checkout.** The buyer is quoted what UPS or FedEx charge for their own cart, and is
  charged exactly that. The carrier is asked once for every service of a shipment, and the answer is
  cached.
- **A checkout that survives a carrier being down.** Per shipping method: hide it, or charge a flat
  amount set per channel. The last rate the carrier gave for that cart is used before either.
- **Packing.** A catalogue of the boxes the store ships in, the packages stored when the order is
  confirmed, and the labels issued against those same packages.
- **Labels, one per package**, issued from the order or in a batch from the shipments grid,
  downloaded from the admin and cancelled with the carrier that issued them.
- **Customs.** HS code and country of origin per variant, the commercial invoice issued in the same
  request as the labels, and who pays duties and taxes at destination.
- **Tracking** in the buyer's account: what the carrier says about their shipment.
- **Retention.** Labels and customs documents are kept for as long as you set, then deleted by a
  command you schedule. The file goes; the record of who issued what stays.

Rates and shipment statuses are kept on the filesystem, so the plugin needs no Redis and no service
beyond the ones Sylius itself needs.

## What it does not do

- Return labels and reverse logistics.
- Scheduled pickups, insurance and value-added services.
- Estimating duties and taxes for the buyer before they pay.
- Printing straight to a thermal printer. The file is handed over; printing it is your system's job.
- Consolidating several orders into one shipment.
- More than one shipping origin per shipment.
- Carriers other than UPS and FedEx, and a third one cannot be added from outside yet: see
  [docs/extending.md](docs/extending.md).

## What you need from the carriers

Not in your hands, so worth starting before anything else:

- A **UPS developer account** and a **FedEx developer account**, each with the client id and secret
  of an API application, and the account number the carrier bills to. Without the account number you
  can be quoted, but you cannot issue a label.
- **FedEx label certification**, to issue real FedEx labels in production. FedEx reviews the labels
  a plugin generates before allowing it, and that takes weeks. Rates and tracking do not need it;
  issuing in production with FedEx does.

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| Sylius | 2.2.6 or newer. Earlier 2.2 releases carry security advisories, and a current Composer refuses to install them |
| Database | MySQL, MariaDB or PostgreSQL. The migrations are written against Doctrine's schema representation rather than an engine's SQL, and every build checks the schema they make on PostgreSQL and on MySQL |

## Installation

Five steps. **On a store with Symfony Flex — Sylius Standard has it — step 1 also does step 2**:
Composer registers the bundle itself and says *Configuring jpmmartin/sylius-shipping-carriers-plugin
… From auto-generated recipe*. A store without Flex does all five by hand.

### 1. Require the package

```bash
composer require jpmmartin/sylius-shipping-carriers-plugin
```

Nothing else works until this is done, and Composer will say so plainly. **On a store with Flex, do
step 3 straight after**: from this moment the bundle is registered, its admin menu links to routes
that do not exist yet, and every admin page answers 500 until they do. The shop keeps working.

### 2. Register the bundle

```php
# config/bundles.php

return [
    // ...
    JpmMartin\SyliusShippingCarriersPlugin\JpmMartinSyliusShippingCarriersPlugin::class => ['all' => true],
];
```

With Flex the line is already there.

Skip this with step 3 done and the store does not start: every page and every console command fails
with *Bundle "JpmMartinSyliusShippingCarriersPlugin" does not exist or it is not enabled*, which at
least names the cause. Skip both, and the plugin is installed but not loaded — **UPS rates** and
**FedEx rates** never appear among the calculators of a shipping method, which looks like a package
that failed to install.

### 3. Import its configuration and routes

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

That adds three entries to the admin's **Configuration** menu — *Shipping origins*, *Package boxes*
and *Carrier credentials* — one to **Catalog** — *Variants missing customs data* — and, in the shop
API, `PUT /api/v2/shop/orders/{tokenValue}/destination-type`.

**Skip the routes and every admin page answers 500**: *Unable to generate a URL for the named route
"jpmmartin_carrier_admin_incomplete_customs_data_index"*, from the menu every page draws. The shop
is unaffected. **Skip the configuration and nothing fails at all**, which is worse: the plugin's
sections of Sylius's own screens — the customs data of a variant, the label actions of a shipment —
are simply not there.

**The prefix is not cosmetic.** Sylius's admin firewall is defined by the admin path, and imported
without it the plugin's screens open outside the admin and fail. Its downloads and its label actions
check that whoever asks is an administrator in any case.

### 4. Generate the key that encrypts the carrier credentials

A carrier's credentials are stored encrypted, with a key of their own, separate from Sylius's payment
key. It lives at `config/encryption/jpmmartin_carrier.key` unless `JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH`
points somewhere else.

**Keep it out of your repository — Sylius Standard's `.gitignore` does not.** It ignores nothing
under `config/encryption/`, so a key generated there is committed with the next `git add`:

```gitignore
# .gitignore
/config/encryption/jpmmartin_carrier.key
```

```bash
bin/console jpmmartin:carrier:generate-key
```

Skip this and the first set of credentials you save fails with *Invalid encryption key.*, which does
not say that the file is missing. Run again, the command asks before replacing the key, and answers
*no* by itself when nobody is there to ask; `--overwrite` replaces it without asking. Either way,
replacing it leaves every credential already stored unreadable, and they have to be typed again.
Keep the file across deployments the way you keep your other secrets.

### 5. Run the migrations

The plugin adds eleven tables, all named `jpmmartin_carrier_*`.

```bash
bin/console doctrine:migrations:migrate
```

**Skip this and every buyer's checkout fails at the address step**, whether or not any shipping
method uses a carrier yet — on PostgreSQL, with *relation "jpmmartin_carrier_order_destination" does
not exist*. So do the screens the plugin adds, and the edit page of every product variant.

## Setting up the store

Nothing is offered to a buyer until the plugin has credentials, an address to ship from and a
shipping method that uses it.

### 1. Carrier credentials

**Configuration → Carrier credentials.** One entry per carrier, with its environment (sandbox or
production), how packages reach it — a scheduled pickup, dropped off at a carrier location, or a
pickup requested when needed — the client id and secret of the carrier's API application, and the
account number. The secret is never shown again; leaving it empty on an edit keeps the current one.

Also **who pays the duties and taxes** of an international shipment at its destination: the
recipient, which is the default and what a checkout that charged none implies, or the store, which
bills them to the account above.

### 2. Shipping origin

**Configuration → Shipping origins.** One per channel: the address packages leave from, the units
the store's weights and dimensions are written in, the maximum weight of a package, the boxes this
origin uses, and whether a delivery counts as a home or a business when the buyer has not said.

A channel with no shipping origin offers no carrier shipping method at all, and says so in the log.

The origin is also where a channel says what it wants in place of the configuration: how long a
carrier is waited for, how long rates, statuses and documents are kept, what labels are printed as,
and the services it adds. Every one of those is optional, and each shows what applies when it is left
empty; see [docs/configuration.md](docs/configuration.md#settings-per-channel).

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

### 4. Shipping methods

**Configuration → Shipping methods**, as always in Sylius. Choose **UPS rates** or **FedEx rates**
as the calculator, then the service, and what the method does when the carrier does not answer:

- **Hide this shipping method** — it is not offered, not assigned by default, and an order cannot be
  completed with it.
- **Offer it at a flat amount** — the amount is set per channel and is charged only when there is no
  rate and no last known rate to fall back on.

The services on offer are the ones the plugin ships with, plus any your configuration or a channel's
shipping origin adds. A method offered in several channels needs a service each of them has; see
[docs/configuration.md](docs/configuration.md).

### 5. Weights and measures

**Every variant a carrier ships needs a weight, a width, a height and a depth** — Sylius's own
shipping fields on the variant — in the units the shipping origin declares. A cart holding one
without them is not quoted at all: no carrier method is offered for it, whatever the method does when
a carrier does not answer, and the log names the variant and what it lacks. Sylius's sample data
declares no weight.

### 6. Customs data

**On each product variant**, under its own section: the HS code — six to ten digits, the code
customs classifies the article by — and the country it was made in. Neither is needed to sell, and
neither is needed to ship inside one country, but **without both, an international shipment is not
issued at all**: the plugin refuses it and says which variant it could not declare, rather than
letting the parcel be stopped at a border where somebody has to pay for it.

A catalogue of thousands cannot be checked one by one, so **Catalog → Variants missing customs
data** lists exactly the ones that are incomplete.

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
refuses leaves the labels issued — and still billed — with the reason on the screen.

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
and neither reason lasts forever. They are stored outside the published directory and handed over
only by the admin — see [docs/configuration.md](docs/configuration.md) for where, and how to point
them elsewhere.

Nothing is deleted on its own. A command deletes what is past the retention — 180 days by default,
or what the order's channel says on its shipping origin — and is meant for cron:

```bash
bin/console jpmmartin:carrier:purge-documents
```

It deletes the file and keeps the row: who issued what and when is still there to read afterwards.
It also collects what an issue left behind when something went wrong between writing the file and
recording it. Running it twice is not a problem: the second run finds nothing left. It comes back as
a failure, with the reason in the log, when a file could not be deleted, which is the one thing a
cron has to notice.

## What the buyer sees

- On the address step, once the channel has a shipping origin, *Where are we delivering?* — **Home**
  or **Business** — which changes the rate the carriers give. Through the shop API it is a `PUT` on
  `/api/v2/shop/orders/{tokenValue}/destination-type` with `{"type": "residential"}` or
  `{"type": "commercial"}`. Left unanswered, the default of the channel's shipping origin is used.
- On the shipping step, each carrier method with the fee that carrier gave for that cart. The order
  is charged exactly the fee that was shown.
- In their account, on an order whose shipment has a tracking number, where it is: the carrier's
  status and the events it knows of, or the tracking number and a notice when the carrier cannot be
  reached.

## Further reading

This README carries the whole path from `composer require` to a despatched order — you should never
*need* the pages below to get there. They are for depth.

| | |
|---|---|
| [Configuration reference](docs/configuration.md) | Every setting and its default, and where the rates, statuses, labels and customs documents are kept |
| [Troubleshooting](docs/troubleshooting.md) | From what you see to the step that was missed |
| [Extending](docs/extending.md) | Every seam a store or another plugin may rely on, and the rule that everything else is internal |
| [Upgrading](docs/upgrading.md) | What a version number promises, what to do on each kind of release, and where to report a problem |

The documentation is English only, and so is the plugin's interface for now.

## Versioning and changes

Released under [Semantic Versioning](https://semver.org/spec/v2.0.0.html): a caret constraint on
this package is safe, and anything that would break an existing store arrives only in a major
release with a written migration note. What changed in each release is in
[CHANGELOG.md](CHANGELOG.md); what to do about it, in [docs/upgrading.md](docs/upgrading.md); how a
release is cut, and what counts as breaking, in [RELEASING.md](RELEASING.md).

## Licence

MIT. See [LICENSE](LICENSE).
