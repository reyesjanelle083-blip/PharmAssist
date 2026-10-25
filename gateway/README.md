<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Inventory feed sync (Step 5C)

`POST /api/v1/integrations/inventory/sync?strict=true|false` (default `false`). Roles: `branch_manager`, `ceo`.
5 requests per minute per IP (counted after the token and role checks). Content types: `application/xml`,
`text/xml`, `application/json` (anything else is 415); body up to 512 KB (413).

The gateway is the Aggregator:

1. the raw body goes to integration-service `POST /internal/v1/inventory-feed/translate` (XSD check, normalised JSON);
2. if that fails, its error envelope is returned as it is (400 `MALFORMED_XML`, 413, 415);
3. branch scope, from the verified token: a manager's `branch_id` must equal the feed's `branchId` (else 403
   `FORBIDDEN`, and inventory-service is not called); the CEO may sync any branch;
4. the normalised JSON goes to inventory-service `POST /internal/v1/inventory/sync` with the verified user headers
   (client-sent `X-User-*` and `X-Internal-Key` headers are never forwarded);
5. inventory-service's envelope is returned.

Each of the two calls waits `GATEWAY_SYNC_TIMEOUT_SECONDS` (default 10; every other route keeps 3 s).
`INTEGRATION_URL` and `INVENTORY_URL` must be set.

The feed is one XML (or JSON) document per branch. The contract is `docs/schemas/inventory-feed.xsd`; samples are in
`docs/samples/` (`inventory-feed.xml`, `inventory-feed.json`, and `inventory-feed-bad.xml` with deliberately bad rows).

```xml
<inventoryFeed branchId="1" generatedAt="2026-10-24T08:00:00+08:00">
  <item>
    <genericName>Amoxicillin</genericName>
    <brandName></brandName>            <!-- may be empty -->
    <strength>500mg</strength>
    <dosageForm>Capsule</dosageForm>
    <quantity>120</quantity>
    <price>12.50</price>
    <batchNo>B-2026-014</batchNo>
    <expirationDate>2027-06-30</expirationDate>
    <minThreshold>20</minThreshold>    <!-- optional -->
  </item>
</inventoryFeed>
```

A medicine is found by its natural key: generic name + brand name + strength + dosage form (exact match, upper or
lower case does not matter, an empty brand matches a medicine stored without a brand). A feed never creates medicines.

Try it in PowerShell (use `curl.exe`, because `curl` is an alias of `Invoke-WebRequest`):

```powershell
$login = curl.exe -s -X POST http://localhost:8000/api/v1/auth/login -H "Content-Type: application/json" -d '{\"email\":\"manager1@pharmassist.test\",\"password\":\"<seed password>\"}' | ConvertFrom-Json
$token = $login.data.access_token

# valid feed (run it twice: the second answer has unchanged = 8)
curl.exe -s -X POST "http://localhost:8000/api/v1/integrations/inventory/sync" -H "Authorization: Bearer $token" -H "Content-Type: application/xml" --data-binary "@docs/samples/inventory-feed.xml"

# same content as JSON
curl.exe -s -X POST "http://localhost:8000/api/v1/integrations/inventory/sync" -H "Authorization: Bearer $token" -H "Content-Type: application/json" --data-binary "@docs/samples/inventory-feed.json"

# bad rows: good rows are applied, bad rows are listed in data.errors
curl.exe -s -X POST "http://localhost:8000/api/v1/integrations/inventory/sync" -H "Authorization: Bearer $token" -H "Content-Type: application/xml" --data-binary "@docs/samples/inventory-feed-bad.xml"

# strict: any bad row rolls everything back (422 FEED_REJECTED)
curl.exe -s -X POST "http://localhost:8000/api/v1/integrations/inventory/sync?strict=true" -H "Authorization: Bearer $token" -H "Content-Type: application/xml" --data-binary "@docs/samples/inventory-feed-bad.xml"
```

`--data-binary` is needed so the file is sent unchanged (`-d` would strip line breaks).

## Reservation routes (Step 6C)

All under `/api/v1` and behind `auth.jwt`. The gateway checks the role, rate limits, and passes the call to
reservation-service, whose envelope and status come back unchanged. reservation-service re-checks role, branch and
ownership itself, so a customer sending `approve` passes the gateway and is refused (403) by the service.
`X-User-*` are built from the verified token; client-sent `X-User-*`, `X-Internal-Key` and `Authorization` are never
forwarded. The only client header passed on is `Idempotency-Key`.

| Method | Route | Roles (checked here) | Limit | Timeout |
|--------|-------|----------------------|-------|---------|
| POST | `/reservations` | customer | 10 per minute per IP | `GATEWAY_RESERVATION_TIMEOUT_SECONDS` (10 s) |
| GET | `/reservations` | customer, pharmacy_technician, pharmacy_assistant, branch_manager, ceo | - | 3 s |
| GET | `/reservations/{id}` | same as the list | - | 3 s |
| PATCH | `/reservations/{id}/status` | customer, pharmacy_technician, pharmacy_assistant, branch_manager | 30 per minute per IP | 10 s |
| GET | `/reservations/{id}/prescription` | customer, pharmacy_technician, pharmacy_assistant, branch_manager, ceo | - | 3 s |

Limits count only requests that passed the token and role checks. A POST or PATCH is never retried: a timeout is `504 UPSTREAM_TIMEOUT`,
no connection `503`, an answer that is not an envelope `502`. The 10 second timeout exists because reservation-service itself
calls inventory-service (up to two calls in a row). `{id}` must be a number, otherwise `404` before anything is called.

- **POST**: JSON or multipart. `Idempotency-Key` is required (`400 IDEMPOTENCY_KEY_REQUIRED`). Only `branch_id`, `notes` and
  `items[].medicine_id` / `items[].quantity` are forwarded. The optional `prescription` image is checked for size here (more than
  4 MB is `413`) and forwarded as `upload.<ext>`, never under the uploader's own file name. Other content types are `415`.
- **GET list**: only `status`, `page`, `per_page` and `branch_id` are forwarded.
- **PATCH status**: only `action`, `reason` and `claim_code` are forwarded.
- **GET prescription**: the only route that answers with a file instead of an envelope. A `200` with a png or jpeg body is streamed with
  `Content-Type`, `Content-Disposition: inline; filename=prescription.<ext>`, `X-Content-Type-Options: nosniff` and
  `Cache-Control: private, no-store`. An error from the service is relayed as an envelope; a `200` that is not an image is a `502`.
  Paths and stored file names are never passed on.

Environment: `RESERVATION_URL` (for example `http://127.0.0.1:8003`) and `GATEWAY_RESERVATION_TIMEOUT_SECONDS` (default 10).

Try it in PowerShell (use `curl.exe`, because `curl` is an alias of `Invoke-WebRequest`). Log in as a customer first; the
seeded customer is `customer.approved@pharmassist.test`:

```powershell
$login = curl.exe -s -X POST http://localhost:8000/api/v1/auth/login -H "Content-Type: application/json" -d '{\"email\":\"customer.approved@pharmassist.test\",\"password\":\"<seed password>\"}' | ConvertFrom-Json
$token = $login.data.access_token
$key = [guid]::NewGuid().ToString()

# create (JSON). Running this again with the same $key replays the same reservation.
curl.exe -s -X POST http://localhost:8000/api/v1/reservations -H "Authorization: Bearer $token" -H "Idempotency-Key: $key" -H "Content-Type: application/json" -d '{\"branch_id\":1,\"items\":[{\"medicine_id\":1,\"quantity\":2}]}'

# create with a prescription image (multipart; items use the bracket names)
curl.exe -s -X POST http://localhost:8000/api/v1/reservations -H "Authorization: Bearer $token" -H "Idempotency-Key: $([guid]::NewGuid())" -F "branch_id=1" -F "items[0][medicine_id]=3" -F "items[0][quantity]=1" -F "prescription=@C:/path/to/prescription.png"

# list, detail, cancel
curl.exe -s "http://localhost:8000/api/v1/reservations?status=pending&per_page=10" -H "Authorization: Bearer $token"
curl.exe -s http://localhost:8000/api/v1/reservations/1 -H "Authorization: Bearer $token"
curl.exe -s -X PATCH http://localhost:8000/api/v1/reservations/1/status -H "Authorization: Bearer $token" -H "Content-Type: application/json" -d '{\"action\":\"cancel\"}'

# the prescription image (binary: save it to a file)
curl.exe -s http://localhost:8000/api/v1/reservations/2/prescription -H "Authorization: Bearer $token" -o prescription.png
```

A technician logs in the same way (`technician1@pharmassist.test`) and approves with
`-d '{\"action\":\"approve\"}'`, or rejects with `-d '{\"action\":\"reject\",\"reason\":\"Unreadable prescription\"}'`.
An assistant completes with `-d '{\"action\":\"complete\",\"claim_code\":\"PA-XXXXXXXX\"}'`.

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
