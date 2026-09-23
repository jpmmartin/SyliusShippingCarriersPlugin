# Troubleshooting

Every entry here names **the step that was missed**, not the component that appeared to fail. Most of
what can go wrong with this plugin does not fail where it was caused: a missing table surfaces at the
buyer's address step, a variant without a weight surfaces as a shipping method that is not there.

If you are reading this during an install, the fastest thing you can do is re-read the README's
install steps in order and check each one — most of what follows is a step that was skipped.

## The store does not start: *Bundle "JpmMartinSyliusShippingCarriersPlugin" does not exist*

**What you see:** every page, and every console command including `cache:clear`, fails with *Bundle
"JpmMartinSyliusShippingCarriersPlugin" does not exist or it is not enabled*.

**What was missed:** README **step 2**. The configuration of step 3 names the bundle, and the bundle
is not registered.

**How to confirm:** `config/bundles.php` has no line for
`JpmMartin\SyliusShippingCarriersPlugin\JpmMartinSyliusShippingCarriersPlugin`. On a store with
Symfony Flex, `composer require` adds it; a store without Flex, or one where the line was removed by
hand, does not have it.

## Every admin page answers 500, naming a route that starts with `jpmmartin_carrier_admin_`

**What you see:** the dashboard and every other admin page fail with *Unable to generate a URL for the
named route "jpmmartin_carrier_admin_…" as such route does not exist*. The shop works.

**What was missed:** the routes of README **step 3**. The plugin adds entries to the admin menu, the
menu is drawn on every admin page, and the routes it links to were never imported. On a store with
Flex this is the state right after `composer require`, until step 3 is done.

**How to confirm:** `bin/console debug:router | grep jpmmartin_carrier_admin` prints nothing. Imported
as the README shows, it prints the plugin's admin routes, every one of them under `/admin/`.

## The plugin's screens answer 500 once you open them, and their addresses do not start with `/admin/`

**What was missed:** the `prefix` of the routes in README **step 3**. Sylius's admin firewall is
defined by the admin path, and the plugin's screens were mounted outside it. Its downloads and label
actions still refuse anyone who is not an administrator; the screens themselves cannot render.

**How to confirm:** `bin/console debug:router | grep jpmmartin_carrier_admin` shows paths such as
`/carrier-credentials/` rather than `/admin/carrier-credentials/`.

## *UPS rates* and *FedEx rates* are not among the calculators of a shipping method

**What was missed:** README **step 2**, in its quieter form — the bundle is not registered and nothing
names it either, so the plugin is installed but not loaded.

**How to confirm:** `bin/console debug:container --tag=sylius.shipping_calculator` lists neither
`ups_rate` nor `fedex_rate`.

## A variant has no customs section, and an order's shipments have no label actions

**What you see:** nothing wrong at all. The admin works, the plugin's own screens open — but its
sections inside Sylius's screens are missing.

**What was missed:** the configuration import of README **step 3**. The routes can be imported without
it, and then the plugin is reachable but none of its pieces of Sylius's screens are registered.

**How to confirm:** `bin/console debug:config sylius_twig_hooks | grep -c JpmMartinSyliusShippingCarriersPlugin`
prints `0`.

## Saving carrier credentials fails with *Invalid encryption key.*

**What was missed:** README **step 4**. There is no key where the store looks for one, and the message
does not say that the file is missing.

**How to confirm:** `bin/console debug:container --env-var=JPMMARTIN_CARRIER_ENCRYPTION_KEY_PATH`
prints the path the store reads, and there is no file at it.

## Every buyer's checkout stops at the address step: *relation "jpmmartin_carrier_order_destination" does not exist*

**What you see:** the address step of the checkout fails for every buyer, whether or not any shipping
method uses a carrier. So do the plugin's admin screens and the edit page of every product variant,
each naming a table that starts with `jpmmartin_carrier_`.

**What was missed:** README **step 5**, the migrations.

**How to confirm:** `bin/console doctrine:migrations:list | grep JpmMartin` shows the plugin's
migrations as not migrated, or shows none at all.

## A carrier shipping method is never offered at checkout

**What you see:** the method is enabled, in the right zone and ticked for the channel, and the buyer
never sees it. With **Hide this shipping method** chosen for when the carrier does not answer, that is
also what every other failure looks like, so **the log is the first place to look**: each reason
writes one line.

**The steps that were missed, with the line each one writes:**

- **The channel has no shipping origin** — *Setting up the store*, step 2. The address step does not
  ask *Where are we delivering?* either, which is the quickest tell.
  *The channel FASHION_WEB has no shipping origin, so no carrier shipping method is offered in it.*
- **The shipping origin is missing part of its address**, which the line names.
  *The shipping origin of the channel FASHION_WEB has no street, so no carrier shipping method is
  offered in it.*
- **A variant in the cart has no weight, or no width, height or depth** — *Setting up the store*,
  step 5. Every variant a carrier ships needs all four — Sylius's own shipping fields on the variant
  — in the units of the shipping origin. Sylius's sample data declares no weight.
  *The shipment cannot be packed, so it cannot be quoted: The variant "…" has no weight declared.*
- **There are no credentials for that carrier** — *Setting up the store*, step 1.
  *The credentials of the carrier fedex could not be read, so the service FEDEX_GROUND could not be
  rated: No credentials are stored for the carrier "fedex".*
- **The credentials were saved with another key**: the key was generated again, or a deployment
  brought a different one. They have to be typed again.
  *… cannot be decrypted with this store's encryption key: it is not the key they were saved with, or
  it is missing.*
- **The carrier refused or did not answer**, and the line carries what it said.
  *The carrier ups could not rate the service 03: …*

**How to confirm:** switch the method to **Offer it at a flat amount** for a moment. If it now appears
at that amount, the carrier could not be asked or would not answer — the credentials or the carrier
itself — and the log says which. If it still does not appear, the flat amount never came into it:
the cart could not be quoted at all, for want of an origin or of a variant's measures, or the reason
is Sylius's own — the zone, the channel, the method's rules.

## The purge command fails in cron

**What you see:** `bin/console jpmmartin:carrier:purge-documents` exits with a failure and says that
some files could not be deleted and are still stored.

**What happened:** the process running it cannot delete files in the documents directory — cron
running as a different user from the one that wrote them, for instance. Each file writes a line:
*The carrier document labels/… could not be deleted, so it is kept until the next purge.*

**How to confirm and fix:** run the command as the user that runs the store. Nothing is lost: a file
that could not be deleted keeps its record as it was, and the next run tries it again.

## Nothing above matches

Read the log. Every call to a carrier that fails, and every shipment the plugin could not quote, pack
or issue, writes an error line saying what it refused and why, naming the carrier, the channel or the
shipment. The symptom you are looking at is usually one line away from its cause in `var/log/`.
