# DATABASE STANDARDS
- UUID primary/public IDs
- FK, unique constraints and indexes are mandatory where invariant exists
- `created_at`, `updated_at`; `deleted_at` only where soft-delete is semantically correct
- Effective-dated tables use `effective_from`, `effective_to`, status/version
- Monetary values use decimal/numeric, never float
- Store UTC timestamps; render Asia/Bangkok in UI
- Migrations are forward-only and reviewed for lock/data risk
- Raw integration payload may use JSONB but canonical data must be typed columns
