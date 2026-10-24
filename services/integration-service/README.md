# integration-service

The middleware of PharmAssist: adapters to outside systems, with timeouts, fallbacks and logging.
Owns `integration_db` (`integration_logs`, `api_cache`). Everything here is internal (`X-Internal-Key` required);
other services and the gateway are the callers. No route here is public.

Capabilities:
- **Distance from a customer to branches** (OSRM with a haversine fallback; plan rows I-3 and I-7), see "Distance endpoint".
- **Prescription OCR** with a local Tesseract (image to text), see "Prescription OCR".

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

## Prescription OCR (local Tesseract)

Reads a photo or scan of a prescription into text, to help customers and pharmacy staff read it. It is a **reading aid, not a verification**:
a pharmacist must always check the original image (see Limitations).

### Contract
`POST /internal/v1/ocr/prescription` (needs `X-Internal-Key`), multipart form with one field `image` (JPEG or PNG).
Reached by clients as `POST /api/v1/prescriptions/ocr` through the gateway: JWT required, roles `customer`, `pharmacy_technician`,
`pharmacy_assistant`, `branch_manager`, 10 requests per minute per IP (counted only after the token and role checks), and the gateway waits up to
`GATEWAY_OCR_TIMEOUT_SECONDS` (20 s) for this route while every other route waits 3 s.

```json
{
  "status": 200, "service": "integration-service", "request_id": "…",
  "data": {
    "ocr_text": "Amoxicillin 500 mg capsule\nTake 1 capsule three times a day\nfor 7 days after meals",
    "confidence": 0.96,
    "ocr_status": "success",
    "source": "tesseract"
  },
  "error": null,
  "warnings": []
}
```
`ocr_text` is trimmed, whitespace is normalised, line breaks are kept, control characters are removed and the text is capped at 5000 characters.
`confidence` is the mean of Tesseract's word confidences (words with confidence -1 are ignored), scaled to 0 to 1.

| `ocr_status` | When | `ocr_text` | Warnings |
|--------------|------|------------|----------|
| `success` | text of at least 15 characters and confidence at least `OCR_MIN_CONFIDENCE` (0.6) | the text | none |
| `low_confidence` | confidence below 0.6, or fewer than 15 characters, or no text found | the text (possibly empty) | `LOW_CONFIDENCE_HANDWRITING_LIKELY`, `TEXT_TOO_SHORT`, `NO_TEXT_FOUND` |
| `unavailable` | Tesseract missing, failed, timed out, circuit open, all slots busy, GD missing, or `OCR_FORCE_FAIL` | `null` | `OCR_UNAVAILABLE`, plus `OCR_BUSY` or `IMAGE_PROCESSING_UNAVAILABLE` |

- An OCR problem is **never a 5xx**: once the file is acceptable the answer is HTTP 200 with `ocr_status: unavailable`, so the client can offer manual entry.
- A bad file is **422 `VALIDATION_FAILED`** (details under `image`): the real type is sniffed from the file content (a text file renamed `.jpg` is rejected,
  a PNG named `.txt` is accepted), JPEG or PNG only, at most 5 MB and 4000 x 4000 pixels. No key is 401.

### How it works
1. Validate the upload (type from the content, size, pixel size).
2. Preprocess with GD: downscale so the longest side is at most 2000 px, flatten transparency onto white, grayscale, raise the contrast, and write a temporary PNG
   with a random name in the system temp directory.
3. Run `tesseract <temp.png> stdout -l eng --psm <OCR_PSM> tsv` with Symfony Process: an **argument array, never a shell string, and no user-supplied value in it**
   (the image path is the random temp file). The TSV output gives word-level confidence.
4. Delete the temp file in a `finally` block (success, failure, crash).
5. Build the result.

Resilience: a missing binary, a non-zero exit, unparsable output or a timeout (`OCR_TIMEOUT_SECONDS`, 15) gives `unavailable`. At most `OCR_MAX_CONCURRENT` (2) jobs run at
once (a cache lock per slot, released even after a crash); extra callers get `OCR_BUSY` straight away. The circuit breaker (shared with the distance code) opens after
**3 consecutive failures** for **60 seconds**; a poor reading (`low_confidence`) is not a failure. Every attempt writes one `integration_logs` row
(`provider=ocr`, `operation=parse_image`, outcome, latency, a fixed reason code such as `binary_not_found`, `timeout`, `nonzero_exit`, `circuit_open`, `busy`).

Providers (`OcrProvider` interface): `TesseractProvider` and `StubOcrProvider` (deterministic sample text, no process, no files; used when `INTEGRATION_MODE=stub` or
`OCR_PROVIDER=stub`). Another engine only needs a new class implementing the interface and an `OCR_PROVIDER` value.

### Privacy
The image is processed **locally on this machine** and is **never stored and never sent to a third party** (no cloud OCR API is used). The only copy is a temporary file that
exists while Tesseract runs and is always deleted. The recognised text, the uploaded file name and the image bytes are **never logged and never written to the database or
the cache**; log rows hold only a reason code, a status and a duration. The gateway forwards the image with a neutral file name (`upload.png`). Stub mode and
`OCR_FORCE_FAIL` are refused in production unless `ALLOW_STUB_IN_PRODUCTION=true`.

### Limitations (please read)
- Tesseract is built for **printed text**. **Handwritten prescriptions will often give `low_confidence` or wrong text** (misread drug names and doses are
  possible, and a confident-looking result can still be wrong). The text is only a help; **a pharmacist must always check the original image** before anything is dispensed.
- Phone photos with shadows, folds, skew or low resolution reduce accuracy; the preprocessing is simple (grayscale and contrast) and does not straighten or crop.
- Only English is installed (`-l eng`), so other languages and special characters are read poorly.
- Speed depends on the machine (about 1 to 3 s for a clean image in the project's tests; larger or noisy images take longer).
- PHP's upload limits apply before our code: the PHP default of 2 MB (`upload_max_filesize` in `php.ini`) rejects larger photos on a local run.
  The Docker image sets 6 MB (`docker/php/uploads.ini`), so the 5 MB rule can be reached there.

### Local setup on Windows
1. Install **Tesseract** (the UB Mannheim Windows installer is the usual one). Keep "English" selected. The default folder is `C:\Program Files\Tesseract-OCR`.
   Check it: `"C:\Program Files\Tesseract-OCR\tesseract.exe" --version` and `--list-langs` (must list `eng`).
2. Tell the service where it is in `services/integration-service/.env`:
   ```
   TESSERACT_BIN="C:/Program Files/Tesseract-OCR/tesseract.exe"
   ```
   **Use forward slashes inside double quotes** (or single quotes around backslashes). Backslashes inside double quotes, such as
   `"C:\Program Files\..."`, are read as escape sequences and the whole `.env` file stops loading (every request and artisan command fails with "The environment file is invalid").
   If `tesseract` is on your `PATH`, `TESSERACT_BIN=tesseract` is enough.
3. Enable PHP's **GD** extension: in `php.ini` remove the `;` in front of `extension=gd` and restart PHP. Without GD the answer is `unavailable` with `IMAGE_PROCESSING_UNAVAILABLE`
   (and the OCR tests are skipped). To raise the 2 MB upload limit set `upload_max_filesize = 6M` and `post_max_size = 8M`.
4. Try it on a local image (one real OCR; refused in stub mode; never run it from tests):
   ```
   php artisan integration:ocr-smoke path\to\image.png
   ```
   It prints the status, confidence, character count, the first 80 characters, and the duration.

In Docker the integration-service image installs Tesseract and GD itself (build arg `INSTALL_TESSERACT=true`, set only for this service in `docker-compose.yml`).

### OCR environment variables
| Variable | Default | Meaning |
|----------|---------|---------|
| `OCR_PROVIDER` | `tesseract` | `tesseract` or `stub` |
| `TESSERACT_BIN` | `tesseract` | binary name or full path (see the Windows note above) |
| `OCR_PSM` | `6` | Tesseract page segmentation mode (0 to 13) |
| `OCR_TIMEOUT_SECONDS` | `15` | the process is killed after this long |
| `OCR_MIN_CONFIDENCE` | `0.6` | below this the result is `low_confidence` |
| `OCR_MAX_CONCURRENT` | `2` | simultaneous OCR jobs; more get `OCR_BUSY` |
| `OCR_FORCE_FAIL` | `false` | every OCR attempt fails at once (testing only) |
| `GATEWAY_OCR_TIMEOUT_SECONDS` | `20` | gateway side: how long the gateway waits for this route (keep it above `OCR_TIMEOUT_SECONDS`) |

### Tests
The OCR tests fake the Tesseract process and generate their images with GD, so they need the GD extension:
`php -d extension=gd vendor/bin/phpunit` (or enable `extension=gd` in `php.ini` and use `php artisan test`). Without GD they are skipped, not failed.

---

## Inventory feed translator (Step 5C)

`POST /internal/v1/inventory-feed/translate` (behind `X-Internal-Key`) is a Message Translator: an XML or JSON branch
feed becomes one normalised JSON structure. It does not touch the database (except one `integration_logs` row,
provider `xml_feed`, which holds the outcome and a fixed reason code, never the feed) and makes no outbound calls.
Values are not judged here (a quantity of -5 is passed on); inventory-service rejects the row with a code.

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

Accepted types: `application/xml`, `text/xml`, `application/json` (else 415). Larger than `FEED_MAX_KB` (default 512) is 413.

Hardening for untrusted XML: UTF-8 only; any `DOCTYPE` or `ENTITY` declaration is refused before parsing (so no
external entities and no entity-expansion bombs); `LIBXML_NONET`, never `LIBXML_NOENT`; libxml internal errors only;
more than 500 `item` elements is refused; then `DOMDocument::schemaValidate` against `resources/schemas/inventory-feed.xsd`
(a copy of `docs/schemas/inventory-feed.xsd`; a test keeps the two identical). A bad feed is 400 `MALFORMED_XML`
(`MALFORMED_JSON` for JSON) with at most 10 short messages such as `Line 12: Element 'quantity': ...`; no server paths.

Output (`data`): `{branch_id, generated_at, items: [{row, generic_name, brand_name, strength, dosage_form, quantity, price, batch_no, expiration_date, min_threshold}]}`.
The same data gives the same output whether it arrived as XML or JSON.

The `xml_feed` provider needs the migration `2026_10_26_100001_add_xml_feed_provider_to_integration_logs`
(`php artisan migrate`).

Direct call in PowerShell (only for testing; normal clients use the gateway):

```powershell
curl.exe -s -X POST http://localhost:8004/internal/v1/inventory-feed/translate -H "X-Internal-Key: <INTERNAL_SERVICE_KEY>" -H "Content-Type: application/xml" --data-binary "@docs/samples/inventory-feed.xml"
```

Tests: `php -d extension=gd vendor/bin/phpunit` (GD is needed by the OCR tests; the feed tests do not need it).

---

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
