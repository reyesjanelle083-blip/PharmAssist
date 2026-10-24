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

## Stock holds (reservation-service only)

A reservation reserves stock with a **hold**. These routes are internal (`X-Internal-Key` required) and are called only by
the reservation service; there is deliberately no gateway route for them.

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/internal/v1/stock/holds` | Create holds: `{reservation_ref, branch_id, items:[{medicine_id, quantity}], ttl_minutes?}`. 201 new, 200 replay |
| GET | `/internal/v1/stock/holds/{reservationRef}` | Current state of a reservation's holds |
| POST | `/internal/v1/stock/holds/{reservationRef}/commit` | Reservation completed: stock leaves the shelf |
| POST | `/internal/v1/stock/holds/{reservationRef}/release` | Reservation cancelled/rejected: stock goes back |

`reservation_ref` is the handle for the whole reservation (1 to 64 chars: letters, digits, `.`, `_`, `:`, `-`); it has one hold row per item.
Limits: 1 to 10 items, quantity 1 to 20, no duplicate `medicine_id`, active branch. `ttl_minutes` defaults to
`INVENTORY_HOLD_TTL_HOURS` hours (48).

### Lifecycle
```
            create                 commit
(nothing) ---------> held ---------------------> committed   (stock_quantity AND reserved_quantity down, movement hold_commit)
                      |  release (cancel / reject)
                      +-----------------------> released     (reserved_quantity down only, no movement)
                      |  expires_at passed, inventory:expire-holds
                      +-----------------------> expired      (reserved_quantity down only)
```
- **Create is all or nothing.** All affected stock rows are locked (`FOR UPDATE`, ordered by id) in one transaction and every item
  must have `stock_quantity - reserved_quantity >= quantity`. Otherwise nothing changes and the answer is
  `409 STOCK_INSUFFICIENT` with `details.items[]` (`requested`, `available`, `sufficient` per item).
  Unknown medicine, or a medicine with no stock row at the branch, is `404 NOT_FOUND`.
- **Idempotent.** The same `reservation_ref` with the same items and branch replays the stored result (200, nothing reserved twice).
  The same `reservation_ref` with a different body is `409 DUPLICATE_REQUEST`.
- **Commit** needs every hold of the reference to be `held`, otherwise `409 INVALID_HOLD_STATE` and nothing changes.
  It also bumps `version`, because `stock_quantity` changed.
- **Release** is safe to repeat: already `released` or `expired` holds answer `200` unchanged; after a commit it is `409 INVALID_HOLD_STATE`.
- `reserved_quantity` can never exceed `stock_quantity` or drop below 0: every write is a guarded `UPDATE`, in addition to the MySQL CHECK constraint.

### Expiry
`php artisan inventory:expire-holds` releases every `held` hold whose `expires_at` has passed (status `expired`). It can run any number
of times. It is scheduled every minute in `routes/console.php`; the scheduler must be running (`php artisan schedule:work`, or a
cron entry calling `schedule:run` every minute). This reconciles holds that nobody committed or released.

### Race test (T20)
```
php artisan inventory:race-test                          # 20 processes compete for 5 units
php artisan inventory:race-test --workers=30 --stock=5
```
Needs the real MySQL database (it refuses SQLite and `APP_ENV=production`). It creates its own temporary medicine and stock row
on the first active branch, starts N separate OS processes that each ask for 1 unit at the same wall-clock moment (`--delay` seconds
of boot time before the start barrier), then prints how many succeeded, how many got `STOCK_INSUFFICIENT`, the final
`reserved_quantity` and the invariant checks, and finally deletes its temporary rows. Expected for 20 workers and stock 5:
exactly 5 succeed, 15 fail, final reserved 5. It also verifies that no pre-existing stock row changed.

---

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
