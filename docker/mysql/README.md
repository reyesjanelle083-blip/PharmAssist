# MySQL container

One MySQL 8 server hosting four schemas, each owned by its own user
(`identity_db`/`identity_user`, `inventory_db`/`inventory_user`,
`reservation_db`/`reservation_user`, `integration_db`/`integration_user`).
Each user has privileges only on its own schema.

## Start
```
cp .env.example .env        # then edit .env and set real passwords
docker compose up -d mysql
docker compose ps           # wait until mysql is "healthy"
```
MySQL listens on `127.0.0.1:3306` only.

## Reset (deletes ALL data)
The init script runs only on an empty volume, so after changing it or the
passwords, wipe the volume:
```
docker compose down -v
docker compose up -d mysql
```
