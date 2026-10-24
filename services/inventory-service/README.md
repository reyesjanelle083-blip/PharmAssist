# inventory-service

Owns `inventory_db`: the medicine catalog, branches, per-branch stock, stock holds, stock movements and stock alerts.
Step 4A adds the schema, models and demo seeders only (no routes or controllers yet).

## Tables
| Table | Purpose |
|-------|---------|
| `branches` | Pharmacy branches (name unique, address, latitude/longitude, `is_active`) |
| `medicines` | One row per medicine: generic/brand name, category, strength, dosage form, `prescription_required`, `counseling_summary`. Unique on (generic_name, brand_name, strength, dosage_form); `brand_name` is `''` for unbranded medicines |
| `branch_inventory` | Price and stock of one medicine at one branch. Unique (branch_id, medicine_id), `version` for optimistic locking, `status` (`available`, `low_stock`, `out_of_stock`). CHECK: `stock_quantity >= 0`, `reserved_quantity >= 0`, `reserved_quantity <= stock_quantity` (enforced by MySQL 8.0.16+) |
| `stock_holds` | Stock reserved for a reservation: one row per reserved line, rows of one reservation share `reservation_ref`; `status` held / committed / released / expired |
| `stock_movements` | History of every stock change (`delta`, `reason`, `actor_id`, `request_id`) |
| `stock_alerts` | Low-stock / out-of-stock alerts (`is_resolved`) |

Available stock is never stored: `available_quantity = stock_quantity - reserved_quantity` (model accessor).
Status rule used by the seeders: stock 0 is `out_of_stock`, stock below `min_threshold` is `low_stock`, otherwise `available`.

Foreign keys exist only between these tables. `actor_id` (a user from identity-service) is a plain integer with no foreign key.
Likewise, any `branch_id` that other services keep (users, reservations) is a plain integer with **no foreign key** to this database;
the services only talk to each other through REST.

## Migrate and seed
Needs MySQL running with the `inventory_db` schema and `inventory_user` (see `docker/mysql/README.md`).
Set `DB_PASSWORD` in `.env` first.
```
php artisan migrate
php artisan db:seed
```
`db:seed` runs `BranchSeeder`, `MedicineSeeder`, `BranchInventorySeeder` in that order. All three are re-runnable
(rows are matched by their unique keys, existing stock rows are never overwritten) and refuse to run when `APP_ENV=production`.

## Seeded branches
| id | Name | Area |
|----|------|------|
| 1 | Ro-Eful Pharmacy | Kaloocan City |
| 2 | The Generics Pharmacy | Manila City (J. Posadas St.) |
| 3 | The Generics Pharmacy - Novaliches Branch | Novaliches, Quezon City (street address not known) |

Branches 1 and 2 come from the previous PharmAssist database; branch 3 is the Novaliches branch named in the project papers.
**Branch id 1 matters:** the seeded staff accounts in identity-service (manager, technician, assistant) use `branch_id = 1`.

The latitude/longitude values are **approximate, verify before demo**. They are rough city-area coordinates written by hand;
no external API was called. Check them (for example on a map) before the distance ranking is demonstrated.

The 16 demo medicines and the prices/quantities (33 stock rows: 25 available, 6 low stock, 2 out of stock) are made up for demos;
medicine names were taken from the old database only as a reference. Counseling texts are general reminders, not medical advice.

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
