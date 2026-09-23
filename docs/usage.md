# Usage

Everything here happens once the plugin is installed and configured: setting the store up so that a
buyer is quoted, and despatching the orders that come out of it.

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

## Where to go next

- [Configuration](configuration.md) — the settings behind all of this.
- [Installation](installation.md) — if something above is missing from your admin.
