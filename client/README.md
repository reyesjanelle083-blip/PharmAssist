# PharmAssist client

React (Vite, JavaScript) single-page app. It talks only to the API gateway (`docs/openapi.yaml` is the contract).

The look is the old PharmAssist look: the old stylesheets are copied into `src/styles/legacy/` (see the README there) and the pages use the old
markup and class names, with Bootstrap 5 CSS (no JavaScript) and the old icon fonts from npm. Fonts are self-hosted (`@fontsource`), no CDN.

## What is in it (step 8A)

- Pages: Home (hero and medicine search), Log in, Register, Verify email, Not found, Forbidden (403), and a "coming soon" page for `/reserve`.
- Search: `GET /availability` with the medicine name and, only when you press "Use my location", your coordinates. Results are cards in the order the gateway ranked them.
- Auth: the token and user are kept in `sessionStorage` (cleared when the tab closes). `ProtectedRoute` needs a login, `RoleRoute` needs one of the given roles. A `401 UNAUTHENTICATED` or `TOKEN_EXPIRED` from any call ends the session and sends you to log in.
- Accessibility buttons on every page, in the look of the old round zoom buttons (bottom right): larger and smaller text (5 steps, 100 to 175 percent, like the old zoom it scales the page content), reset, high contrast and readable font. The choices are saved in `localStorage`. The default look is the old look; **High contrast** switches to the accessible black, white and yellow theme (`styles/contrast.css`). Skip link, visible keyboard focus, labelled fields, live regions for errors and results, and it respects `prefers-reduced-motion`. The old default look has known contrast problems; they are listed in the paper's limitations and are not fixed in the default theme.
- Polish step: the search bar is in the top bar (as on the old pages); a sidebar with Home, Branches, Guide and log in / out; About Us and Contact Us dialogs (native `<dialog>`); a branches section with an OpenStreetMap map (Leaflet 1.9.4 and react-leaflet 5.0.0, which needs React 19) and one card per branch; a carousel of our own "how it works" slides; the pages `/guide`, `/privacy` and `/terms`.
- Contact details (email, optional phone and social links) are set in `src/config/site.js`. A phone line or a social icon shows only when its value is set.
- The map is the only thing that loads from another server (the OpenStreetMap tile server). It is mentioned in the Privacy Notice.
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
