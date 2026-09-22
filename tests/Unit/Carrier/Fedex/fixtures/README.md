# FedEx fixtures — not checked against FedEx

These responses were written from `resources/models/rates-transit-times/v1.json` of
`shipstream/fedex-rest-sdk` v1.6.0 (`RatcResponseVO` and `ErrorResponseVO`) and from the OAuth token fields
Saloon reads, not recorded from FedEx. Until they are checked against the sandbox, a test that passes with
them proves the mapping follows the documented schema, not that it reads what FedEx actually sends.

- `rate-quote.json`: a quote with two services, one with an account rate and a list rate, and one with only
  a list rate.
- `error.json`: the error body FedEx sends with a 4xx status.
- `token.json`: a granted OAuth access token.
- `track.json`: a tracking response for a delivered shipment with two scans.
- `ship-international.json`: a shipment that crosses a border, written from `resources/models/ship/v1.json`
  (`TransactionShipmentOutputVO`), with the commercial invoice among its `shipmentDocuments`, after a document of another kind.
