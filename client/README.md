# PharmAssist client

React (Vite, JavaScript) single-page app, plain CSS. It talks only to the API gateway (`docs/openapi.yaml` is the contract).

## What is in it (step 8A)

- Pages: Home (hero and medicine search), Log in, Register, Verify email, Not found, Forbidden (403), and a "coming soon" page for `/reserve`.
- Search: `GET /availability` with the medicine name and, only when you press "Use my location", your coordinates. Results are cards in the order the gateway ranked them.
- Auth: the token and user are kept in `sessionStorage` (cleared when the tab closes). `ProtectedRoute` needs a login, `RoleRoute` needs one of the given roles. A `401 UNAUTHENTICATED` or `TOKEN_EXPIRED` from any call ends the session and sends you to log in.
- Accessibility toolbar on every page: text size (A-, A+, reset: 100, 112, 125, 150, 175 percent), high contrast, readable font. The choices are saved in `localStorage`. Skip link, visible focus, labelled fields, live regions for errors and results, 44 px touch targets, and it follows `prefers-color-scheme`, `prefers-contrast` and `prefers-reduced-motion`.
- Every error shows a friendly sentence and a small "Reference: ..." line (the gateway's `X-Request-Id`) so a problem can be found in the gateway logs.

## Setup

```
cp .env.example .env     # VITE_API_BASE_URL=http://localhost:8000/api/v1
npm install
```

Only public values go in `.env` (`VITE_*` variables are visible in the browser). Never put keys or secrets here.

## Run, build, lint

```
npm run dev       # dev server at http://localhost:5173
npm run build     # production build in dist/
npm run preview   # serve the production build locally
npm run lint      # oxlint
```

## Which backend servers must be up

| Needed for | Servers |
|------------|---------|
| Search (Home) | gateway (8000), inventory-service (8002) and MySQL with the inventory seed. integration-service (8004) is optional: without it distances are estimated by the gateway and the page shows "Distances are estimates right now" |
| Log in, Register, Verify email | gateway (8000), identity-service (8001) and MySQL with the identity seed |

The gateway must allow the page's origin: `CORS_ALLOWED_ORIGINS` in `gateway/.env` has to include `http://localhost:5173`.
Start the servers as described in `docs/RUNNING.md`.

## Layout of `src/`

```
api/        Axios instance, envelope unwrapping, ApiError, error messages, one file per area
context/    AuthContext (session) and AccessibilityContext (toolbar settings)
components/ Layout, toolbar, search, result card, form field, error message, route guards
pages/      one file per page
styles/     tokens (colours, themes, text sizes), base, layout, components
assets/     the PharmAssist logo and mark, taken from the old site
```

## Notes

- The register form says plainly that the ID photo is used only to verify identity and is deleted after the decision.
- Your location is read only when you press the button, is kept in memory for the search, and is never stored or put in the address bar.
- Not in this step: reservation pages, staff pages, a map, tests beyond lint and build.
