# Testing and data safety

- Use Pest and the existing test conventions. Add focused tests for changed
  behavior, including validation, authorization, API contracts, and relevant
  boundaries; prefer factories and framework fakes over external services.
- Keep tests deterministic and offline. Fake queues, storage, HTTP, OCR, and
  other external boundaries as appropriate.
- Commit only synthetic or anonymized test fixtures. Never commit real
  invoices, receipts, tax identifiers, vehicle identifiers, account data, or
  other personal or confidential information.
- Keep fixture-driven seeders limited to local/testing environments, validate
  malformed input explicitly, and report skipped invalid rows visibly.
- Run the narrowest relevant Pest tests, then Pint and PHPStan for PHP changes.
  Use `docs/TESTING.md` for the repository's exact commands and coverage
  expectations.
