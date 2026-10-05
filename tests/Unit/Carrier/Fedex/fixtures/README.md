# FedEx fixtures

Two kinds, and a test that passes with one of them proves different things.

## Recorded from FedEx's sandbox

Copied as FedEx sent them. A test that passes with these reads what FedEx actually sends.

- `rate-quote-sandbox.json`: the answer of 2026-09-28 to the rate request the tests make, Chicago to Seattle.
  Six Express services, each with only the account rate the plugin asks for; no Ground (see
  `tests/Sandbox/FedexSandboxTest.php`).
- `track-sandbox.json`: the answer of 2026-09-28 for the sandbox's tracking number `123456789012`, which
  FedEx has given to five shipments. It sends all five, the oldest first, each with its scans newest first.
- `ship-two-packages-sandbox.json`: the answer of 2026-09-30 to a shipment of two packages from Chicago to
  Seattle with `FEDEX_2_DAY`, with both PDF labels. FedEx sends no `packageSequenceNumber`; the order of
  `pieceResponses` is the packages'.
- `cancel-sandbox.json`: the cancellation of that kind of shipment, accepted.
- `cancel-refused-sandbox.json`: the same cancellation asked again. FedEx answers 200 with
  `cancelledShipment: false` and a message that does not say why.

- `ship-international-sandbox.json`: the answer of 2026-10-05 to a shipment from Chicago to London with
  `FEDEX_INTERNATIONAL_PRIORITY` and an invoice of two lines, each with its weight. One exception to «as FedEx
  sent it»: every `encodedLabel` and `image` is cut to its first 1024 characters, which still decode to the
  start of a PDF. FedEx sends the commercial invoice twice, among `shipmentDocuments` and again inside
  `completedShipmentDetail`, and in full the answer weighs 433 KB.

## Written from the SDK's schemas, not checked against FedEx

These responses were written from `resources/models/rates-transit-times/v1.json` of
`shipstream/fedex-rest-sdk` v1.6.0 (`RatcResponseVO` and `ErrorResponseVO`) and from the OAuth token fields
Saloon reads, not recorded from FedEx. A test that passes with them proves the mapping follows the documented
schema, not that it reads what FedEx actually sends.

- `rate-quote.json`: a quote with two services, one with an account rate and a list rate, and one with only
  a list rate. The sandbox, asked for account rates, sends only those, so the choice between the two is
  still tested against this one.
- `error.json`: the error body FedEx sends with a 4xx status.
- `token.json`: a granted OAuth access token.
- `track.json`: a tracking response for a delivered shipment with two scans.
- `ship-international.json`: a shipment that crosses a border, written from `resources/models/ship/v1.json`
  (`TransactionShipmentOutputVO`), with the commercial invoice among its `shipmentDocuments`, after a document of another kind.
