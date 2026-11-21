# Project-3 Laravel Backend

Enterprise-grade REST API (`/api/v1`) for Project-3, built with Laravel 12, Laravel Sanctum Bearer tokens, and MySQL.

## Quick Local Setup

```bash
cd backend
cp .env.example .env
# Configure DB_* in .env
composer install
php artisan key:generate
php artisan migrate --seed --seeder=BaselineSeeder
php artisan app:create-ceo
php artisan serve
```

## Key Commands

- `php artisan app:create-ceo` — Create the initial CEO account interactively (no hardcoded passwords).
- `php artisan app:set-password {email}` — Securely set or reset a user password via CLI.
- `php artisan db:seed --class=InitialUsersSeeder` — Idempotently seed initial staff accounts (one per role) with unique generated passwords.
- `php artisan app:deployment-check` — Run read-only production/cPanel deployment checks safely without mutating records.
- `php artisan app:send-daily-reminders` — Run Baghdad-time (`Asia/Baghdad`) due-soon, overdue task, and late bug reminders.
- `php artisan test` — Run PHPUnit Unit & Feature tests.

## Documentation

- [`docs/backend-implementation-plan.md`](./docs/backend-implementation-plan.md)
- [`docs/backend-open-questions.md`](./docs/backend-open-questions.md)
- [`docs/database-schema.md`](./docs/database-schema.md)
- [`docs/api-documentation.md`](./docs/api-documentation.md)
- [`docs/openapi.yaml`](./docs/openapi.yaml)
- [`DEPLOY_CPANEL.md`](./DEPLOY_CPANEL.md)
- [`deployment/CPANEL_COMPLETE_GUIDE_AR.md`](./deployment/CPANEL_COMPLETE_GUIDE_AR.md)
