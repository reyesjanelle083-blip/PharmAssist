# Running PharmAssist locally (Docker)

Requires Docker Desktop with Compose v2.

## 1. Configure
```
cp .env.example .env
```
Edit `.env` and replace every `change_me` / placeholder:
- `APP_KEY`: generate with
  `docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32));"`
- `INTERNAL_SERVICE_KEY`: any long random string.
- The MySQL passwords: any strings for local use.

`.env` is git-ignored. Never commit it.

## 2. Start
```
docker compose up --build
```
The first build takes a few minutes. The four services wait for MySQL to be healthy;
the gateway waits for the four services.

| What | URL |
|------|-----|
| Gateway | http://localhost:8000 |
| Identity / Inventory / Reservation / Integration | http://localhost:8001 / 8002 / 8003 / 8004 |
| React client (dev server) | http://localhost:5173 |
| MySQL | 127.0.0.1:3306 |

All ports are bound to 127.0.0.1 only.

## 3. Check health
```
docker compose ps                       # every Laravel service should be "healthy"
curl http://localhost:8000/internal/v1/health
curl -H "X-Internal-Key: <your INTERNAL_SERVICE_KEY>" http://localhost:8001/internal/v1/health
```
A service called without the key returns 401 `INVALID_INTERNAL_KEY`.

## 4. Scheduled jobs (reservation expiry)
A reservation is held for `RESERVATION_TTL_HOURS` (48 by default). Nothing expires by itself: the Laravel scheduler runs
`reservations:expire` (reservation-service) and `inventory:expire-holds` (inventory-service) every minute. With Docker the
`reservation-scheduler` and `inventory-scheduler` containers do this. When you run the services by hand
(`php artisan serve`), also run, in each of those two service folders:
```
php artisan schedule:work
```
Without it a reservation past its hold time stays "pending" or "approved" (the pages say that the hold time has passed).

## 5. Stop / reset
```
docker compose down          # stop, keep database data
docker compose down -v       # stop and DELETE the MySQL volume (schemas are recreated on next start)
```
After changing a Dockerfile or application code, rebuild with `docker compose up --build`.
