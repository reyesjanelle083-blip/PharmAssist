# PharmAssist client

React (Vite, JavaScript) single-page app. It talks only to the API gateway.

## Setup
```
cp .env.example .env     # set VITE_API_BASE_URL to the gateway URL
npm install
```

## Run, build, lint
```
npm run dev       # dev server at http://localhost:5173
npm run build     # production build in dist/
npm run preview   # serve the production build locally
npm run lint      # oxlint
```

Only public values go in `.env` (`VITE_*` variables are visible in the browser). Never put keys or secrets here.
