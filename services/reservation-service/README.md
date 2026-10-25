# reservation-service

Owns `reservation_db`. A customer reserves medicines at one branch; this service holds the stock through
inventory-service and stores the reservation. Laravel 12, port **8003**. Every route is internal
(`X-Internal-Key` required); the public routes will be added to the gateway in a later step.

Step 6A covers creating and reading a reservation. Not built yet: approve, reject, cancel, complete, expiry,
notifications, the gateway routes, and prescription OCR.

## Tables (`reservation_db`)

`user_id`, `branch_id` and `medicine_id` are plain integers owned by other services: there are no foreign keys across schemas.

| Table | Purpose |
|-------|---------|
| `reservations` | `claim_code` (UNIQUE, `PA-` + 8 random characters), `user_id`, `branch_id`, `status` (pending, approved, completed, rejected, cancelled, expired), `ocr_status` (not_required, not_processed, success, low_confidence, unavailable), `ocr_text`, `ocr_confidence`, `prescription_path` (private disk, never returned), `hold_ref` (UNIQUE, the `reservation_ref` sent to inventory-service), `notes`, `expires_at`, `idempotency_key` |
| `reservation_items` | one row per medicine: `medicine_id`, `medicine_name` and `unit_price` (snapshots taken when the stock is held), `quantity` |
| `reservation_status_history` | every status change: `from_status` (null for the first row), `to_status`, `actor_id`, `reason`, `request_id`, `created_at` |
| `idempotency_keys` | `user_id`, `key`, `request_hash`, `status_code`, `response_json`; UNIQUE(`user_id`, `key`) |

Prescription verification (SOAP) was dropped, so there are no `verification_*` columns. OCR output will be stored in `ocr_*`.

Commands (not run automatically):

```
php artisan migrate
```

## Endpoints

Identity comes from the headers the gateway builds from the verified JWT: `X-User-Id`, `X-User-Role`, `X-User-Branch`.
The service checks them itself again. Missing or malformed identity headers are `401`.

| Method | Path | Who | Answer |
|--------|------|-----|--------|
| POST | `/internal/v1/reservations` | `customer` only (else 403) | 201 the reservation |
| GET | `/internal/v1/reservations/{id}` | the owner, staff (`pharmacy_technician`, `pharmacy_assistant`, `branch_manager`) of the same branch, `ceo` | 200 the reservation with `history`; any other caller 403; unknown id 404 |

### POST /reservations

Header `Idempotency-Key` (8 to 128 characters) is required: missing is `400 IDEMPOTENCY_KEY_REQUIRED`.
Body as JSON, or as multipart with the prescription image (`items[0][medicine_id]`, `items[0][quantity]`, ...):

| Field | Rule |
|-------|------|
| `branch_id` | required integer |
| `items` | 1 to 10 items, each with a unique `medicine_id` and `quantity` 1 to 20 |
| `notes` | optional, up to 500 characters |
| `prescription` | optional file, JPG or PNG (checked by content), up to 4 MB |

Answer `201` (`data`): `id`, `claim_code`, `user_id`, `branch_id`, `status` (`pending`), `prescription_uploaded`, `ocr_text`,
`ocr_status`, `items[]` (`medicine_id`, `medicine_name`, `unit_price`, `quantity`), `notes`, `expires_at`, `created_at`;
`warnings` is empty. The file path is never returned.

### The create flow

```
customer -> [idempotency key row] -> medicine lookup -> stock hold -> one DB transaction -> 201
                                       (inventory)       (inventory)   reservation, items, history, file
```

1. **Idempotency key row first** (see below).
2. **Medicine lookup**: `GET /internal/v1/medicines/{id}` on inventory-service for each distinct item: unknown medicine is `404`;
   if any item needs a prescription and no file was sent the answer is `422 PRESCRIPTION_REQUIRED`, before any stock is touched.
3. **Stock hold**: `POST /internal/v1/stock/holds` with a new UUID `reservation_ref` (stored as `hold_ref`) and
   `ttl_minutes` = `RESERVATION_TTL_HOURS` x 60, so the reservation and the hold expire together.
   The hold answer provides each item's `medicine_name` and `unit_price` (the branch's current price), which become the snapshots.
4. **One DB transaction**: `reservations` (status `pending`; `ocr_status` is `not_required` when no item needs a prescription,
   otherwise `not_processed`), `reservation_items`, the first `reservation_status_history` row (from null to pending, with
   `actor_id` and `request_id`), and then the prescription file on the private disk under a random name; its path is saved.
   The claim code uses a secure random function over an alphabet without `I`, `L`, `O`, `0`, `1`; a clash with an existing code draws a new one.

Calls to inventory-service carry `X-Internal-Key` and the same `X-Request-Id`, wait at most 3 seconds, and a POST is never retried.

| Inventory-service result | Our answer |
|--------------------------|------------|
| `409 STOCK_INSUFFICIENT`, `404` | passed through with its `details`; nothing is stored |
| other error envelope | passed through |
| cannot connect | `503 SERVICE_UNAVAILABLE` |
| timeout | `504 UPSTREAM_TIMEOUT`, plus one best-effort release of that `reservation_ref` (a hold may exist) |
| not an envelope | `502 BAD_UPSTREAM_RESPONSE`, plus one best-effort release |

### Compensation (no distributed transaction)

If anything fails **after** the hold was created (the DB transaction, or saving the file), the hold is released with
`POST /internal/v1/stock/holds/{ref}/release`, up to **3 attempts** with a short pause (`RELEASE_PAUSE_MS`). The whole
transaction is rolled back (so is any stored file), the failure is logged with the `request_id` and the `reservation_ref` only
(no personal data), and the answer is `500 INTERNAL_ERROR`. If all three releases fail, the hold is still given back by
inventory-service's own expiry job (`inventory:expire-holds`) when it passes its `expires_at`.

### Idempotency

- The key is per customer: UNIQUE(`user_id`, `key`). The row is inserted **first**, so two simultaneous requests cannot both create a reservation: only one insert wins.
- The same key and the same request (same branch, items, notes and, for multipart, the same file hash; item order does not matter) **replays the stored answer with its original status code** and creates nothing new.
- The same key with a different request is `409 DUPLICATE_REQUEST`.
- The same key while the first request is still running is `409 DUPLICATE_REQUEST` with `details.reason = in_progress`.
- A request that **fails** (stock shortage, unknown medicine, missing prescription, timeout, ...) removes its key row, so the client can retry with the same key. Requests refused before the row is written (401, 403, 400, 422 form validation) never use up a key.

## Environment variables

| Variable | Purpose |
|----------|---------|
| `SERVICE_NAME`, `INTERNAL_SERVICE_KEY` | envelope `service` name; the shared secret expected in `X-Internal-Key` |
| `DB_*` | `DB_DATABASE=reservation_db`, `DB_USERNAME=reservation_user` |
| `INVENTORY_URL` | inventory-service base URL (for example `http://127.0.0.1:8002`) |
| `INTEGRATION_URL` | integration-service base URL. **Not used yet**: prescription OCR will be connected after the reservation steps |
| `RESERVATION_TTL_HOURS` | reservation and stock-hold lifetime, default 48 |
| `INVENTORY_TIMEOUT_SECONDS` | optional, default 3 |
| `RELEASE_PAUSE_MS` | optional pause between release attempts, default 200 |

## Tests

```
php artisan test
```

They use SQLite in memory and `Http::fake` for inventory-service (the extensions `pdo_sqlite` and `sqlite3` must be enabled in `php.ini`).
