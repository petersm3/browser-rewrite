# Standalone Browser

Pure static site version of the browser-rewrite application. Runs entirely in the browser using [sql.js](https://sql.js.org/) (SQLite compiled to WebAssembly). No server infrastructure required.

## How it works

The SQLite database (`db/browser.db`) is fetched by the browser and loaded into memory via WebAssembly. All queries run client-side - there is no backend.

## Quick start

The `db/browser.db` file is pre-built and committed to the repository. To run locally, serve the `standalone/` directory with any static file server:

```bash
# Python
cd standalone && python3 -m http.server 8000

# Node.js (npx, no install needed)
cd standalone && npx serve .

# Then open http://localhost:8000
```

## GitHub Pages

Enable GitHub Pages on the repository (Settings > Pages > Source: main branch, folder: `/standalone`) and the application is live with no build step.

## Rebuilding the database (optional)

To regenerate the database with different data or a different number of accessions:

```bash
cd standalone
npm install          # installs better-sqlite3 (build tool only)
npm run build        # generates db/browser.db with 20,000 accessions
```

Custom number of accessions:
```bash
node build_database.js 5000
```

## Stack

- [sql.js](https://sql.js.org/) - SQLite compiled to WebAssembly (loaded from CDN at runtime)
- [Bootstrap 3](https://getbootstrap.com/) + [jQuery 1.11.3](https://jquery.com/) (loaded from CDN)
- Vanilla JavaScript (no framework, no build step for the frontend)
