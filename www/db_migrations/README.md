# Database Migrations

Migration files upgrade existing production installations. A fresh install
never needs them: load `www/schema.sql`, which always represents the complete
current database structure.

## Naming convention

`YYYY-MM-DD_description.sql`, e.g. `2026-08-09_initial_schema.sql`.

## Rules

- Run migrations in chronological order.
- Write migrations to be idempotent (safe to run more than once) when possible.
- Whenever the database changes, update `schema.sql` to the complete current
  state AND add a migration file here for production installations.
