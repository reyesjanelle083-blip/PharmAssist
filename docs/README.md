# PharmAssist docs

## API endpoints by tier

Generated from `docs/openapi.yaml` (base path `/api/v1`). Roles: public = no login; technician, assistant and manager are branch staff (own branch only).

### Tier 0 - demo-critical (19)

| Method | Path | Role | Module | Summary |
|--------|------|------|--------|---------|
| POST | `/auth/register` | public | auth | Register a customer account (starts as pending approval) |
| POST | `/auth/verify-email` | public | auth | Confirm the emailed 6-digit code |
| POST | `/auth/login` | public | auth | Log in and receive a JWT |
| POST | `/auth/logout` | customer, technician, assistant, manager, ceo | auth | Invalidate the current token |
| GET | `/auth/me` | customer, technician, assistant, manager, ceo | auth | Current user |
| GET | `/medicines` | public | catalog | Search the medicine catalog (generic or brand name) |
| GET | `/medicines/{id}` | public | catalog | Medicine detail |
| GET | `/branches` | public | catalog | List branches |
| GET | `/branches/{id}/inventory` | technician, assistant, manager, ceo | inventory | Stock of one branch (staff only for their own branch) |
| PUT | `/branches/{id}/inventory/{medicineId}` | technician, manager | inventory | Update stock, price or threshold of one medicine at a branch |
| GET | `/availability` | public | availability | Branches with stock, ranked by travel time (mashup of inventory and OSRM) |
| POST | `/reservations` | customer | reservations | Create a reservation (holds stock) |
| GET | `/reservations` | customer, technician, assistant, manager, ceo | reservations | List reservations (customer - own, staff - own branch, CEO - all) |
| GET | `/reservations/{id}` | customer, technician, assistant, manager, ceo | reservations | Reservation detail (owner, staff of the branch or CEO) |
| PATCH | `/reservations/{id}/status` | customer, technician, assistant, manager | reservations | Change reservation status |
| GET | `/reservations/{id}/prescription` | customer, technician, assistant, manager | reservations | Stream the stored prescription image (private, never a public URL) |
| POST | `/prescriptions/ocr` | customer, technician, assistant, manager | prescriptions | Read a prescription image into text (nothing is stored) |
| POST | `/integrations/inventory/sync` | manager, ceo | integrations | Import a branch inventory feed (XML or JSON) |
| GET | `/health` | public | health | Aggregated health of the gateway and its services |

### Tier 1 - strongly desired (13)

| Method | Path | Role | Module | Summary |
|--------|------|------|--------|---------|
| POST | `/auth/forgot-password` | public | auth | Send a password reset link (always answers the same, no enumeration) |
| POST | `/auth/reset-password` | public | auth | Set a new password using the emailed token |
| GET | `/users/pending` | ceo | auth | ID-verification queue |
| PATCH | `/users/{id}/verification` | ceo | auth | Approve or reject a customer account |
| GET | `/medicines/{id}/counseling` | public | catalog | Plain-language guidance (internal data combined with openFDA) |
| POST | `/branches` | ceo | catalog | Create a branch |
| PUT | `/branches/{id}` | ceo | catalog | Replace a branch record |
| PATCH | `/branches/{id}` | ceo | catalog | Partially update or deactivate a branch |
| GET | `/stock-alerts` | technician, manager, ceo | inventory | Low-stock alerts |
| GET | `/reports/branch-summary` | assistant, manager, ceo | inventory | Branch KPIs |
| GET | `/reports/system-summary` | ceo | inventory | All-branch KPIs |
| GET | `/notifications` | customer, technician, assistant, manager, ceo | reservations | Own notifications |
| PATCH | `/notifications/{id}/read` | customer, technician, assistant, manager, ceo | reservations | Mark a notification as read (owner only) |

### Tier 2 - only if time remains (5)

| Method | Path | Role | Module | Summary |
|--------|------|------|--------|---------|
| GET | `/staff` | manager, ceo | auth | List staff (a manager sees only their own branch) |
| POST | `/staff` | manager, ceo | auth | Create a staff account (a manager only for their own branch) |
| GET | `/messages` | customer, manager, ceo | reservations | List own message threads (plan lists only "/messages*"; shape here is proposed) |
| POST | `/messages` | customer, manager | reservations | Customer to branch, or branch manager to CEO (proposed shape) |
| POST | `/scans/medicine` | public | prescriptions | Read a medicine package photo and suggest matching catalog entries |

Total: 37 operations.

## Files

- [PROJECT_PLAN.md](PROJECT_PLAN.md) - full project plan
- [openapi.yaml](openapi.yaml) - gateway API contract
- [RUNNING.md](RUNNING.md) - run the stack with Docker
