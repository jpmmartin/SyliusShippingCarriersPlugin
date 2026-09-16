# UPS fixtures — not checked against UPS

These responses were written from the schemas in `openapi.yaml` of `shipstream/ups-rest-php-sdk` 2.3.5
(`RATEResponseWrapper`, `ErrorResponse`, and the OAuth token response), not recorded from UPS. Until they
are checked against the sandbox, a test that passes with them proves the mapping follows the documented
schema, not that it reads what UPS actually sends.

- `rate-shop.json`: a `Shop` rate response with two services, one with negotiated charges and one without.
- `rate-single-service.json`: the same response with a single service, which UPS sends as an object
  instead of a list.
- `error.json`: the error body UPS sends with a 4xx status.
- `token.json`: a granted OAuth access token.
