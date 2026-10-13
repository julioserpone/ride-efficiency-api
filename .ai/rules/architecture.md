# Application architecture

- Keep domain calculations in focused classes under `app/Actions`; invoke
  existing actions through their established `execute()` method.
- Put reusable integrations and resolution logic in `app/Services`, and keep
  HTTP request handling in controllers. Use queued jobs for work that should
  not block an API request, such as OCR processing.
- Use Eloquent models and relationships for persistence. Do not add a
  repository, generic service layer, or new architectural abstraction unless
  it solves a demonstrated need and matches the affected domain.
- Use the installed `julioserpone/gig-economics` APIs for shared profitability
  calculations. Preserve decimal precision and explicit energy units across
  persistence and API boundaries.
- Confirm the installed Laravel and package versions before using
  version-specific APIs; follow existing project patterns and the matching
  official documentation.
