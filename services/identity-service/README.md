# identity-service

Owns `identity_db`: users, roles, email/ID verification, password resets and audit logs.
Step 3A adds the schema, models and test-account seeder only (no login or routes yet).

## Database setup
```
php artisan migrate
php artisan db:seed --class=UserSeeder
```
Needs MySQL running with the `identity_db` schema and `identity_user` (see `docker/mysql/README.md`).
Set `DB_PASSWORD` and `SEED_PASSWORD` in `.env` first. The seeder fails if `SEED_PASSWORD` is empty
and refuses to run when `APP_ENV=production`. It can be re-run safely (accounts are matched by email).

## Auth endpoints (internal, need `X-Internal-Key`)
`POST /internal/v1/auth/register` (JSON: email, password, first_name, last_name, contact_no; no ID photo), `POST /internal/v1/auth/verify-email`,
`POST /internal/v1/auth/login` (returns an HS256 JWT, 60 min), `GET /internal/v1/users/{id}`.
Requires `JWT_SECRET` (at least 32 characters) in `.env`; without it login fails closed with a 500.
No email is sent yet: with `APP_ENV=local` the 6-digit verification code is written to `storage/logs/laravel.log`.
A customer's flow is: register, verify the email with the 6-digit code, log in. There is no ID upload and no admin approval of the account;
login is refused only for an unverified email (`EMAIL_NOT_VERIFIED`), an inactive account (`ACCOUNT_INACTIVE`) or a lock (`ACCOUNT_LOCKED`).
The `users.verification_status`, `rejection_reason` and `id_document_path` columns come from the old ID-approval flow. They are kept (nothing
reads or writes them any more) so no data is dropped; remove them in a later migration once nobody needs the old data.

## Tests
Tests use in-memory SQLite. On machines where `pdo_sqlite` is disabled in `php.ini` (default on Windows), run:
```
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```
(`php artisan test` starts a child process that does not inherit `-d` flags.)

## Seeded test accounts
All use the password from `SEED_PASSWORD`. Never commit that value.

| Email | Role | Branch |
|-------|------|--------|
| ceo@pharmassist.test | ceo | - |
| manager1@pharmassist.test | branch_manager | 1 |
| technician1@pharmassist.test | pharmacy_technician | 1 |
| assistant1@pharmassist.test | pharmacy_assistant | 1 |
| customer.approved@pharmassist.test | customer | - |

All are email-verified. A `customer.pending@pharmassist.test` row may still exist in a database seeded before this change; it can now log in like any verified customer.

**Note:** `branch_id = 1` is only a reference (no foreign key, branches live in inventory-service).
The inventory seed must create a branch with id 1, otherwise the staff accounts point at a branch that does not exist.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
