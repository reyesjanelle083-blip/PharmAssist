# integration-service

The middleware of PharmAssist: adapters to outside systems, with timeouts, fallbacks and logging.
Owns `integration_db` (`integration_logs`, `api_cache`). Everything here is internal (`X-Internal-Key` required);
the gateway's availability aggregator is the caller. No public route exists.

Step 5A provides one capability: **distance from a customer to branches** (plan rows I-3 and I-7).

## Distance endpoint
`POST /internal/v1/distance`

```json
{
  "origin": { "lat": 14.5995, "lng": 120.9842 },
  "destinations": [
    { "branch_id": 1, "lat": 14.6507, "lng": 120.983 },
    { "branch_id": 2, "lat": 14.583, "lng": 121.005 }
  ]
}
```
```json
{
  "status": 200, "service": "integration-service", "request_id": "…",
  "data": { "items": [
    { "branch_id": 1, "distance_km": 6.17, "travel_min": 10, "source": "osrm" },
    { "branch_id": 2, "distance_km": 4.91, "travel_min": 9,  "source": "osrm" }
  ] },
  "error": null,
  "warnings": []
}
```
- Items come back **in the order sent**. `source` is `osrm`, `haversine` (an estimate) or `stub`.
- If any item used the fallback, `warnings` contains `"DISTANCE_FALLBACK"`. The status is still 200: an OSRM problem is never a 5xx.
- Validation (422 `VALIDATION_FAILED`): `origin` and 1 to 25 `destinations` with unique `branch_id`; every `lat`/`lng` must be a JSON number inside the
  Philippines box (latitude 4.5 to 21.5, longitude 116 to 127). Coordinates are never echoed back.
- OSRM minutes are rounded **up**; kilometres have 2 decimals.

## How it works
```
cache hit? --yes--> answer (source osrm)
   |no
circuit open? --yes--> Haversine estimate for all items (warning)
   |no
OSRM Table API (one attempt by default: 1.8 s timeout and 1.8 s deadline; OSRM_RETRIES can add retries)
   |success: items with a null value fall back one by one; a complete answer is cached for 10 minutes
   |failure: Haversine estimate for all items (warning), failure counted by the circuit breaker
```
- **OSRM adapter** (`OsrmProvider`): one request, the origin as the only source and every branch as a destination, annotations
  `duration,distance`, coordinates in `lon,lat` order, `User-Agent` from `HTTP_USER_AGENT`. A `null` for one destination falls back for that item only.
- **Haversine adapter** (`HaversineProvider`): great-circle distance times `HAVERSINE_ROAD_FACTOR` (1.3), travel time from `HAVERSINE_AVG_SPEED_KMH` (25).
  It is an estimate, not a road distance. Both providers implement `DistanceProvider`, so another routing engine can be added without touching `DistanceService`.
- **Retry** (off by default, `OSRM_RETRIES=0`): the code is still there and is configurable. When enabled, it only retries timeouts, transient connection errors
  (refused, reset, DNS) and 5xx, with a short backoff, and never starts an attempt after the deadline. Never for 429 (the public server is rate limiting: do not push),
  malformed bodies, other 4xx, or TLS/certificate errors (those fail the same way every time).
- **Circuit breaker** (Laravel cache): after **3 consecutive failed OSRM calls** OSRM is skipped for **60 seconds** and every request answers with the fallback.
  After 60 s one request is let through ("half open"): a success closes the circuit, a failure opens it again at once. Cache keys:
  `integration:osrm:consecutive_failures` and `integration:osrm:open_until`. `php artisan cache:clear` resets it.
- **Cache** (`api_cache`, 10 minutes): key = SHA-256 of the origin rounded to 3 decimals (about 100 m) plus the sorted destination ids and coordinates.
  Only complete OSRM answers are cached, never fallbacks.
- **Logging** (`integration_logs`): one row per OSRM attempt group (`provider=osrm`, `operation=table`, `outcome` success / fallback / failure / timeout,
  `status_code`, `latency_ms`, a fixed reason code, the `request_id`). A request answered because the circuit is open also writes a `fallback` row (`circuit_open`).
  A failing log or cache write never changes the answer.

## Timeout decision: one attempt, 1.8 s, not the plan's 5 s
Plan section 4 allows 5 s for external calls, but the **gateway only waits 3 s** for any downstream service. If this service were still waiting for OSRM
at 3 s, the gateway would answer 504 and the customer would get an error instead of a (slightly less precise) ranking. The OSRM call is therefore a
**single attempt** (`OSRM_RETRIES=0`) with a **1.8 s timeout and a 1.8 s deadline** (`OSRM_TIMEOUT_SECONDS`, `OSRM_DEADLINE_SECONDS`).

Why 1.8 s and not more: the request has to fit in 3 s *including everything else*. Live measurements on the project machine (Windows, PHP dev server) showed

- a **base overhead of about 0.5 to 0.8 s per request** even when no outbound call is made (web server, Laravel boot, database for cache and log);
- an OSRM attempt group that can **overshoot its limit** a little (a 2.5 s group measured 2.5 to 3.07 s);
- an earlier setup (1.5 s timeout, one retry, 2.5 s deadline) that produced a **3.06 s end-to-end fallback**, which is already past the gateway's 3 s.

So the OSRM budget has to be about 1.8 s to leave room for the overhead and the haversine fallback. A single attempt also fits what the public demo server
does: it answered in roughly 1 to 1.9 s from the Philippines and sometimes not at all, so a retry would rarely rescue a request inside the budget. On a slow
day the fallback is used, which is the intended behaviour. On Linux or Docker the overhead should be smaller; that has not been measured.

The retry code is **still there and configurable**: `OSRM_RETRIES=1` enables one retry (with `OSRM_BACKOFF_MS` between attempts). If you do that, raise
`OSRM_DEADLINE_SECONDS` only as far as the gateway's 3 s minus the overhead allows, because the deadline stops any new attempt once it is used up. The tests set
the timeout, retries and deadline explicitly, so they do not depend on these defaults.

## Environment variables
| Variable | Default | Meaning |
|----------|---------|---------|
| `INTEGRATION_MODE` | `live` | `stub` = no network, deterministic haversine numbers with `source: "stub"` (tests, demos) |
| `OSRM_BASE_URL` | `https://router.project-osrm.org` | OSRM server (public demo, no SLA: never load test it) |
| `OSRM_TIMEOUT_SECONDS` | `1.8` | per-attempt timeout |
| `OSRM_RETRIES` | `0` | extra attempts after the first (0 = single attempt; `1` turns the retry on) |
| `OSRM_DEADLINE_SECONDS` | `1.8` | hard limit across all attempts (must stay well below the gateway's 3 s) |
| `OSRM_BACKOFF_MS` | `150` | pause between attempts |
| `HTTP_USER_AGENT` | placeholder | sent to OSRM; put a real contact in your own `.env`, never in git |
| `HAVERSINE_ROAD_FACTOR` | `1.3` | straight line to road distance |
| `HAVERSINE_AVG_SPEED_KMH` | `25` | travel time estimate |
| `DISTANCE_FORCE_FAIL` | `false` | make OSRM fail immediately to test the fallback (plan T12) |
| `ALLOW_STUB_IN_PRODUCTION` | `false` | stub mode and forced failures are refused when `APP_ENV=production` unless this is `true` |

## Privacy
The customer's coordinates **are sent to OSRM** (that is what the call is for) but are **never stored**: the cache key is a hash, the cached payload holds only
branch ids, kilometres and minutes, `integration_logs` has no coordinate columns and only fixed reason codes (never a raw exception, which would contain the URL),
the response does not echo them, and the `User-Agent` contact is never logged. A test asserts all of this.

## Database and commands
```
php artisan migrate                  # integration_logs, api_cache
php artisan integration:osrm-smoke   # ONE real OSRM request (Manila center to the 3 seeded branches); refuses INTEGRATION_MODE=stub
```
Run the smoke command by hand only: never from tests or loops, the public demo server must not be stressed.
On Windows, PHP often has no CA bundle (`curl.cainfo` empty in `php.ini`), which shows up as the reason `tls_error`; download `cacert.pem` and set
`curl.cainfo` and `openssl.cafile`. Docker images do not have this problem.

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
