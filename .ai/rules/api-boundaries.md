# API boundaries

- This repository is a Laravel JSON API consumed by separate web and mobile
  applications. Keep product UI in those clients; preserve the `/api/v1`
  boundary, route naming, authentication, and established JSON response shapes.
- Validate request data with the matching Form Request and use its validated
  data. Keep controllers focused on HTTP orchestration and explicit responses.
- Authenticate protected endpoints with Sanctum and authorize every
  user-owned record before reading or changing it. Scope queries to the
  authenticated user where practical; never expose another user's data.
- Treat response fields, status codes, and error messages as client contracts.
  Cover them in tests and update the API documentation when a public contract
  changes.
- Do not expose credentials, tokens, full request payloads, OCR source images,
  or unnecessary personal data in responses, logs, or errors.
