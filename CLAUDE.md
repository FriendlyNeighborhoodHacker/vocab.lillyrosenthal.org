See ALL FILES in the docs/ directory.

## Local development

- Create a MySQL database `vocab_lillyrosenthal` and load `www/schema.sql` into it.
- Copy `www/config.local.php.example` to `www/config.local.php` and fill in DB credentials.
- Run: `php -S localhost:8080 -t www`
- Sign in with the seeded admin: email `lilly`, password `lilly` (change it after first login).
- Tests: `php unit-tests/tools/phpunit.phar -c unit-tests/phpunit.xml` (drops/recreates `vocab_lillyrosenthal_test` from `www/schema.sql` on every run).
- When the database changes, update `www/schema.sql` to the complete current state AND add a migration in `www/db_migrations/`.
