# CLAUDE.md

Guidance for Claude Code (and other AI agents) working in this repository.

## What this package is

`iabduul7/filament-auto-transliterate` — inline, as-you-type transliteration and
translation for Filament form inputs. A user types Roman Urdu, presses space, and
the word is rewritten in Urdu script in place. It is a **keyboard-style input
helper**, not a content-translation workflow.

**Core invariant (do not break):** `transliterate` mode (same sounds, target
script) and `translate` mode (by meaning) are strictly separate. Transliterate mode
must NEVER fall through to a meaning-based provider, and a miss in either mode
leaves the user's text unchanged. Tests assert this; keep them passing.

## Layout

- `src/Services/TranslationService.php` — orchestrator: cache → provider chain → optional char fallback.
- `src/Providers/*` — one class per backend; `provider_map` + per-mode chains in config. Unconfigured providers are skipped.
- `src/Models/TranslationCache.php` — permanent DB cache; hash-indexed lookups (MySQL generated column vs. app-supplied hash elsewhere — see model comments).
- `src/Macros/TranslatableMacro.php` — `->translatable()` / `->autoTransliterate()` on form components; emits `data-fat-*` attributes the JS reads.
- `src/Http/Controllers/TranslationController.php` + `routes/web.php` — auth-gated, throttled endpoints (they proxy external APIs; never make them public).
- `resources/js/translation-overlay.js` — framework-free overlay; bundled to `resources/dist/` via `npm run build` (esbuild, IIFE — Filament loads it as a plain script, so no top-level exports in the bundle).
- `resources/views/hooks/toggle.blade.php` — header on/off toggle (Alpine + Tailwind).
- `database/migrations/*.stub` — publishable migration; tests load the stub directly.
- `docs/` — design plans for the next iteration (multi-language, learning loop, switcher UI, free-tier quality, monetization). Read these before implementing related features, and update them when the design changes.

## Commands

- `composer test` — Pest test suite (Orchestra Testbench, in-memory SQLite).
- `npm run build` — rebuild `resources/dist/` after touching `resources/js` or `resources/css`. The built assets are committed; JS/CSS changes are incomplete without a rebuild.
- Code style: Laravel Pint (`vendor/bin/pint`), enforced by a GitHub workflow.

## Conventions

- Tests are Pest, `Http::preventStrayRequests()` + `Http::fake()` — never hit real networks in tests.
- Comments explain constraints and "why", not "what"; match the existing density.
- New providers implement `Contracts\TranslationProvider`, extend `AbstractProvider`, and are wired via `provider_map` — never hard-code a provider into the service.
- Config keys are flat, snake_case, env-overridable where operational.
- User input must stay out of logs unless `log_requests` is explicitly on.

## Process notes

- Commits: clear, descriptive messages; include Claude as co-author
  (`Co-Authored-By:` trailer) on AI-assisted commits.
- Keep `CLAUDE.md`, `README.md`, `CHANGELOG.md`, and `docs/` in sync with code
  changes — documentation updates are part of the change, not a follow-up.
