# reservation-service

Owns `reservation_db`. A customer reserves medicines at one branch; this service holds the stock through
inventory-service and stores the reservation. Laravel 12, port **8003**. Every route is internal
(`X-Internal-Key` required); the public routes will be added to the gateway in a later step.

Built so far: creating a reservation (6A), status changes, auto-expiry, the list and the prescription image (6B).
Not built yet: notifications, the gateway routes, and prescription OCR.

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
| GET | `/internal/v1/reservations` | every role, scoped (see below) | 200 `{items, meta}` |
| GET | `/internal/v1/reservations/{id}` | the owner, staff (`pharmacy_technician`, `pharmacy_assistant`, `branch_manager`) of the same branch, `ceo` | 200 the reservation with `history`; any other caller 403; unknown id 404 |
| PATCH | `/internal/v1/reservations/{id}/status` | see the table below | 200 the updated reservation |
| GET | `/internal/v1/reservations/{id}/prescription` | owner, same-branch staff, `ceo` | 200 the image; others 403; no file 404 |

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

## Status changes (step 6B)

```
                  approve                  complete (claim code)
   pending ------------------> approved ------------------------> completed
      |  \                        |
      |   \ reject (reason)       | cancel (owner)
      |    `---------> rejected   |
      | cancel (owner)            |
      `---------> cancelled <-----'
   pending or approved --(expires_at passed, automatic)--> expired
```

`completed`, `rejected`, `cancelled` and `expired` are final.

| Action | From | To | Who | Stock in inventory-service |
|--------|------|----|-----|----------------------------|
| `approve` | pending | approved | `pharmacy_technician` or `branch_manager` of the reservation's branch | nothing |
| `reject` (`reason` required) | pending | rejected | `pharmacy_technician` or `branch_manager` of the branch | hold released |
| `cancel` | pending, approved | cancelled | the owning `customer` only | hold released |
| `complete` (`claim_code` required) | approved | completed | `pharmacy_technician`, `pharmacy_assistant` or `branch_manager` of the branch | hold committed (stock leaves the shelf) |
| expiry | pending, approved | expired | the system (`reservations:expire`) | hold released |

The CEO cannot change a status. Wrong role, wrong branch, or not the owner is `403`. A move that is not in the table
(including repeating an action that already succeeded) is `409 INVALID_TRANSITION` with `error.details.current_status`.
Body: `{action, reason?, claim_code?}`; `reason` is at most 255 characters (the history column).

Other answers:

| Answer | When |
|--------|------|
| `422 INVALID_CLAIM_CODE` | the code does not match (compared with `hash_equals` after trimming and upper-casing); the right code is never revealed |
| `409 RESERVATION_EXPIRED` | `complete` after `expires_at` |
| `409 HOLD_NOT_AVAILABLE` | inventory-service says the hold cannot take this action (for example commit on a released or expired hold, release on a committed one); logged |
| `503`, `504`, `502` | inventory-service unreachable, too slow, or not answering with an envelope (as in 6A). **Nothing is changed**, so the same request can be repeated |
| `500 INTERNAL_ERROR` | the status could not be saved after inventory-service had already acted; a `critical` log entry holds the `request_id` and the reservation id. **Repeat the same request**: it repairs itself |

How an action runs: (1) read the reservation under `lockForUpdate` and validate the move; (2) call inventory-service,
outside any DB transaction; (3) in one transaction update the status with the guard `WHERE status = <expected>` and write
the `reservation_status_history` row (from, to, `actor_id`, reason, `request_id`). If another request changed the status in
between, the guard fails and the answer is `409 INVALID_TRANSITION`. Repeating after a crash works because release is
idempotent in inventory-service and a commit that finds the holds already `committed` (`INVALID_HOLD_STATE`) counts as done.

## Auto-expiry

```
php artisan reservations:expire
```

Finds `pending` and `approved` reservations whose `expires_at` is in the past (50 at a time), releases each hold
(inventory-service answers 200 when it is already released or expired), and sets `expired` with a history row
(`actor_id` null, reason `auto-expiry`). It is safe to run any number of times; one failing reservation is logged and
does not stop the others (the command then exits with code 1 and the failed ones are retried on the next run).

It is scheduled **every minute** in `routes/console.php`. **The scheduler must be running** or nothing expires:

```
php artisan schedule:work
```

(In production use one cron entry: `* * * * * php artisan schedule:run`.) inventory-service has its own
`inventory:expire-holds` job as a second line of defence for holds nobody released.

## List and prescription image

`GET /reservations?status=&page=&per_page=&branch_id=`: newest first, `per_page` 1 to 50 (default 20), `meta` as in the
other services (`page`, `per_page`, `total`, `last_page`). A customer sees only their own reservations, staff only their branch
(`X-User-Branch`; a `branch_id` in the query is ignored), the CEO everything (optionally one `branch_id`). Each entry has `id`,
`claim_code`, `status`, `branch_id`, `expires_at`, `created_at`, `item_count` (number of lines) and `total_price`. It never contains the
prescription path or OCR text.

`GET /reservations/{id}/prescription` streams the private file. The content type is detected from the file itself (png or jpeg;
anything else is 404), with `Content-Disposition: inline; filename=prescription.<ext>`, `X-Content-Type-Options: nosniff` and
`Cache-Control: private, no-store`. The stored path and file name are never exposed.

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
