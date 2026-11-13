# Filament Auto Transliterate — Next Session Handover & Backlog

**Package:** `iabduul7/filament-auto-transliterate` (Free MIT Core)  
**Current Version:** `v0.3.4` (Released 2026-11-07)  
**Filament Directory Listing:** https://filamentphp.com/plugins/abdullah-shahneel-auto-transliterate  
**Local Demo App:** `C:\Users\abdul\Documents\PhpstormProjects\Google Work\filament-transliterate-demo` (`http://127.0.0.1:8006/admin`, `admin@example.com` / `password`)  
**Companion Paid Package:** `C:\Users\abdul\Documents\PhpstormProjects\Personal\filament-auto-transliterate-pro`

---

## 1. Current State Summary (Completed Through `v0.3.4`)

- **Framework & Version Support:** PHP `^8.2` (8.2–8.5), Laravel `^11.0 || ^12.0 || ^13.0`, Filament `^3.2 || ^4.0 || ^5.0`.
- **Multi-Script Positioning (`v0.3.1`):** Arabic (`ar`) is the default `target_language` out of 10 built-in scripts (`ar`, `ur`, `fa`, `hi`, `mr`, `ne`, `bn`, `pa`, `ru`, `el`).
- **Filament v4/v5 Self-Contained Styling (`v0.3.2`–`v0.3.3`):** Header toggle, status dot, language chip, dropdown (`max-height: 22rem`), and RTL `.fat-pinned-badge` input padding (`padding-right: 2.5rem !important`) use self-contained `.fat-*` CSS rules in `resources/css/translation-overlay.css` (compiled to `resources/dist/filament-auto-transliterate.css`).
- **Media Assets (`v0.3.3`–`v0.3.4`):**
  - `docs/images/demo.gif` (10s animated multi-script demo, embedded in `README.md`)
  - `docs/images/screenshot.png` (2560×1440 dark-mode PNG)
  - `docs/images/screenshot-light.jpg` (2560×1440 light-mode JPEG)
- **CI Supply-Chain Hardening (`v0.3.4`):** All GitHub Actions in `.github/workflows/*.yml` are pinned to 40-character commit SHAs and `.github/dependabot.yml` has `cooldown` windows configured so Plumb / Filament Plugin Directory Package Health reaches **100/100**.

---

## 2. What Is Left to Do in This Free Package

### A. Extension Points for `filament-auto-transliterate-pro` (`v0.4.0` — Completed 2026-11-13)

1. **[DONE] Runtime Provider Registration on `TranslationService`:**
   - Added `TranslationService::extend(string $key, Closure|string|TranslationProvider $concrete, array|TranslationMode|string|null $modes = null, string $position = 'after'): void`.
2. **[DONE] Cache Lookup Hook, Hit Hook & Prune Protection:**
   - Added `TranslationService::scopeCacheLookupUsing(?Closure $callback)` and `TranslationService::onCacheHit(?Closure $callback)`.
   - Added `config('filament-auto-transliterate.protected_sources')` (default `['user_correction']`), respected by both `TranslationCache::prune()` and `TranslationCache::cacheTranslation()`.
3. **[STRUCK — NOT NEEDED] Candidate List Payload on `/translate` & `fat:before-apply` Event:**
   - *Decision (2026-11-13):* Struck. Free core's `/translate` runs only after a word is committed (Space/Enter/Tab/blur), whereas Pro's `->withCandidatePopover()` queries live candidates while typing via its own `POST /filament-auto-transliterate/candidates` endpoint. Keeping candidate popover logic in Pro avoids unused payload/event overhead in the free core.
4. **[DONE] Field Extra-Attribute Passthrough for Companion Fields:**
   - `fat:applied` DOM event and scalar `data-fat-*` attributes are live.

### B. Developer Experience & Testing Enhancements (Operation 90 W02 Backlog)

1. **`FilamentAutoTransliterate::fake()` Testing Helper (`v0.4.0`):**
   - Provide a first-party fake (`TranslationService::fake(['marhaba' => 'مرحبا'])`) so developers installing this plugin can easily assert transliteration behavior in their own application's Pest/PHPUnit test suites without hitting external HTTP APIs or needing complex mocks.
2. **Expanded Built-in Offline Dictionaries (`resources/lang/*.json`):**
   - `resources/lang/ur.json` is rich; expand `resources/lang/ar.json`, `hi.json`, and `fa.json` with the top 300–500 common personal names, honorifics, cities, and business terms (e.g., *Mohammed, Abdullah, Al-Riyadh, Dubai, Lahore, Karachi, Mumbai, Company, Street, Building, Invoice*).
3. **Per-Language & Edge-Case Test Matrix (Jules / Antigravity 20-Issue Backlog):**
   - Dedicated Pest test files per script (`fa`, `mr`, `ne`, `bn`, `pa`, `ru`, `el`) verifying `Support\Languages::phpScriptPattern()`, `jsScriptPatternSource()`, RTL detection, and Google Input Tools `itc` codes.
   - Livewire v3 morphing / Filament `Repeater` and `Modal` dynamic field initialization tests.

### C. Operation 90 W03 Launch & Marketing Tasks (16–22 Nov)

1. **30–45s Plugin Trailer (W03):**
   - Record screen capture using the local demo app (`C:\Users\abdul\Documents\PhpstormProjects\Google Work\filament-transliterate-demo`).
   - Combine with 2–3 Veo 3.1 Fast clips in Flow + Lyria sting.
2. **Community Launch Posts:**
   - Publish launch write-up + trailer on `dev.to`, `r/laravel`, `r/FilamentPHP`, and LinkedIn once the Laravel News link goes live.
3. **README "Pro Edition" Callout:**
   - Add a clean section at the bottom of `README.md` comparing Community (Free) vs. Pro features (`->transliterateInto()`, Admin Glossary Resource, Candidate Popover, Bulk Table Action, AI Context Providers) linking to the Pro repository/storefront.

---

## 3. Useful Commands & Environment Notes

- **Node / Asset Build (Windows):**
  ```powershell
  C:\nvm4w\nodejs\node.exe build.js
  ```
- **Code Style & Static Analysis:**
  ```powershell
  vendor/bin/pint --test
  vendor/bin/phpstan analyse --no-progress
  vendor/bin/pest
  ```
- **Git Workflow:**
  - Always develop on `dev`, open PR `dev` → `main`, merge, tag `vX.Y.Z` on `main` (`git -c tag.gpgsign=false tag -a vX.Y.Z`), push tag, and sync `main` back into `dev`.
