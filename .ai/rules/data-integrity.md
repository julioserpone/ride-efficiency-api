# Data integrity

- Treat migrations as deployed history: make schema changes in new,
  reversible migrations rather than editing an existing migration.
- Preserve database constraints and ownership relationships. Use explicit
  foreign keys and deletion behavior for new relationships.
- Keep measurement units, currencies, and monetary precision explicit. Reuse
  the existing model casts and domain value objects instead of converting
  money through intermediate floating-point arithmetic.
- Do not make destructive schema or data changes without explicit approval and
  a safe migration path.
