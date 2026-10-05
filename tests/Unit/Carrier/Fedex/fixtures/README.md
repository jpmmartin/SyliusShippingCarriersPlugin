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

## Written by hand, for what the sandbox does not show

Written from the SDK's schemas (`resources/models/*/v1.json` of `shipstream/fedex-rest-sdk`) and kept because each
one holds a case the recorded answers do not. Where FedEx's sandbox gave an answer of the same kind, the fields the
plugin reads were checked against it on 2026-09-28 to 2026-10-05, and are the same.

- `rate-quote.json`: a quote with two services, one with an account rate and a list rate, and one with only
  a list rate. The sandbox, asked for account rates, sends only those, so the choice between the two is
  still tested against this one.
- `error.json`: the body of a 4xx. FedEx's are the same shape, `transactionId` and `errors[]` with `code` and
  `message`; some add a `parameterList` the plugin does not read.
- `token.json`: a granted OAuth access token. FedEx's own is never recorded, since it is a credential.
- `track.json`: a delivered shipment with two scans, the oldest first: the reverse of FedEx's order, on purpose,
  so the test proves the plugin puts the newest first itself.
- `ship.json` and `ship-two-packages.json`: shipments of one and of two packages, with labels a few bytes long,
  for the tests that check what is sent. What the plugin reads of them is shaped as in
  `ship-two-packages-sandbox.json`; they also carry a `packageSequenceNumber`, which FedEx does not send.
- `cancel.json` and `cancel-refused.json`: a cancellation accepted and one refused. The refusal gives a reason,
  that the shipment was already picked up, where the sandbox's never says why, so the test checks the reason
  is kept.
- `ship-international.json`: a shipment that crosses a border, with the commercial invoice among its
  `shipmentDocuments` after a document of another kind, an order the sandbox did not produce.
