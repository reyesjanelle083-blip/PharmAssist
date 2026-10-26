# PharmAssist (SIA) - project rules

## Stack
- Laravel for the gateway and ALL services; React + Vite for the client; MySQL.
- No Python or Node services.

## Working rules
- Build only the step the user names, then stop.
- Never commit secrets, .env, uploads, logs or credentials. Commit only .env.example.
- Each service owns its own schema: no cross-schema joins or foreign keys. Services talk via REST only.
- Use the response envelope and X-Request-Id from plan section 9.1.
- Follow docs/PROJECT_PLAN.md, with the overrides below (they win over the plan).

## Overrides to docs/PROJECT_PLAN.md
a. No SOAP service and no legacy-verification-sim; prescription verification was dropped.
b. Module M7 is now Prescription OCR (image to text via an OCR adapter in integration-service), Tier 0.
c. The XML inventory feed (I-10) is Tier 0.
d. Distance provider is OSRM (free).
e. Hosting is Railway.
f. TTS, STT and gesture recognition are out of scope.
g. No customer ID photo and no customer account approval: a customer registers, verifies the email with the 6-digit code, and logs in. (Reservation approve/reject by staff is unchanged.)
