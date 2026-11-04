# Contributing

Thanks for helping out. Please read `CLAUDE.md` for the architectural rules (mode isolation, cache hashing, JS overlay gotchas) before changing behaviour.

## Setup

```bash
composer install
npm install
```

## Checks

```bash
composer test      # Pest (vendor/bin/pest)
composer lint      # Pint, check only
composer format    # Pint, apply fixes
composer analyse   # PHPStan / Larastan (level 5)
```

Tests use in-memory SQLite and fake all HTTP; never hit real networks. JS/DOM behaviour (spinner, toggle, switcher) is not covered by the PHP suite, so check it in a real browser.

## Frontend assets

JS/CSS are authored in `resources/js` and `resources/css` and built with esbuild:

```bash
npm run build
```

The built `resources/dist/*` files **are committed** and are what Filament serves. If you change any JS/CSS source, build and commit `resources/dist`; CI fails when the committed dist is stale.

## Branch flow

1. Branch from `dev` (or work on `dev`).
2. Open a PR into `dev`; releases go `dev` -> `main` via PR, then an annotated `vX.Y.Z` tag on `main`.
3. `main` requires green CI (tests matrix, Pint, PHPStan, dist check).

## Pull requests

Fill in the PR template checklist: tests, Pint, PHPStan, `npm run build` + dist committed, and docs (`README.md`, `CHANGELOG.md`, `CLAUDE.md`, `docs/`) updated alongside the code.

Security issues: see `SECURITY.md`, do not open a public issue.
