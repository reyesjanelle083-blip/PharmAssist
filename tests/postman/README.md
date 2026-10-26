# Postman / Newman tests (auth through the gateway)

Collection: `pharmassist.postman_collection.json` (Postman Collection v2.1)
Environment: `local.postman_environment.json` (variables only, no secrets)

| Folder | What it covers |
|--------|----------------|
| 01 Auth | T01 register, T03 login (customer + CEO, manager, technician, assistant with role and branch checks), T06c `/auth/me` with a valid token |
| 02 Negative | T02 duplicate email, T04/T04b wrong password vs unknown email (same message), T05 login before the email is verified (`EMAIL_NOT_VERIFIED`), T06/T06b missing and tampered token, 404 envelope without stack trace |
| 99 Rate limit (run last) | T31 sends logins until a 429 appears, then checks `TOO_MANY_REQUESTS` and `Retry-After` |

Every request asserts the HTTP status, that the envelope has `status`, `service`, `request_id`, `data`, `error` and `warnings`, and that `request_id` is not empty. Error cases also assert the exact `error.code`.

Email verification with the 6-digit code is **not** tested here: the code only exists in the local identity-service log, so a collection cannot read it. It is covered by the identity-service PHPUnit tests.

## Prerequisites
1. MySQL running with the identity migrations applied and the seeder run
   (`php artisan migrate` and `php artisan db:seed --class=UserSeeder` in `services/identity-service`).
2. identity-service running on port 8001 and the gateway on port 8000
   (`php artisan serve --port=8001` / `--port=8000`, or `docker compose up`).
3. The gateway's `INTERNAL_SERVICE_KEY` and `JWT_SECRET` equal identity-service's.
4. The seeded password: the value of `SEED_PASSWORD` in `services/identity-service/.env`.
5. Node.js (Newman is fetched by `npx`).

## Run
From the repository root:

```
npx newman run tests/postman/pharmassist.postman_collection.json -e tests/postman/local.postman_environment.json --env-var "seed_password=<password>"
```

Replace `<password>` with the seeded password. Do not write it into the environment file or commit it.

### Wait 60 seconds between runs
The gateway allows 10 logins per minute per IP, and the whole collection counts toward that
(8 logins before the rate-limit test). Run it again too soon and the earlier requests return 429 and fail.
Registration is limited to 5 per minute (the collection uses 2).

## Export results
```
mkdir -p tests/postman/results
npx newman run tests/postman/pharmassist.postman_collection.json -e tests/postman/local.postman_environment.json --env-var "seed_password=<password>" --reporters cli,json,junit --reporter-json-export tests/postman/results/newman.json --reporter-junit-export tests/postman/results/newman.xml
```
`tests/postman/results/` is git-ignored. Keep the JSON/XML or a screenshot of the CLI summary as testing evidence.

## Notes
- Each run registers a new customer with a unique generated email (`random_email`); these accounts accumulate in `identity_db`.
- `access_token` and `random_email` are written into the environment at run time by the scripts. Newman does not save them back to the file unless you pass `--export-environment`; do not commit an exported environment.
