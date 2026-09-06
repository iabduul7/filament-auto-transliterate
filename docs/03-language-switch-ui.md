# 03 — UI for language switching

## Goal

Once multiple target languages exist (doc 01), the user needs to see **which
language is active** and switch it without leaving the keyboard flow. Today the
header has a single on/off toggle (`resources/views/hooks/toggle.blade.php`,
registered at `GLOBAL_SEARCH_BEFORE`); this plan extends that surface.

## Design decisions

### Where the switcher lives: the header, next to the existing toggle

Evaluated options:

| Option | Verdict |
| ------ | ------- |
| **A. Header toggle + adjacent language dropdown** | ✅ chosen — one glance shows on/off *and* active language; matches where Filament users already look (next to global search); zero per-field clutter |
| B. Per-field dropdown on every translatable input | ❌ heavy DOM/visual cost on data-entry forms with dozens of fields; per-field choice is better served by developer-set pins |
| C. Keyboard shortcut cycling languages | ➕ nice addition later, not a primary UI — undiscoverable on its own |
| D. User profile / panel setting page | ❌ too far from the typing flow; switching mid-form is a real use case (bilingual records) |

### The header cluster

```
[ 🌐 toggle ]  [ اردو ▾ ]        ← enabled: language chip visible
[ 🌐 toggle ]                    ← disabled: chip hidden (nothing to configure)
```

- The existing on/off button is unchanged in behavior.
- When enabled, a compact **language chip** appears beside it showing the active
  language's **native name** (`اردو`, not "Urdu" — the person typing Roman Urdu
  reads Urdu). Clicking opens an Alpine dropdown listing all configured languages,
  each as `native — label` (`اردو — Urdu`), with a check on the active one.
- Selection calls `window.FilamentAutoTransliterate.setTargetLang(code)`, persists
  to `localStorage.fat_target_lang` (same per-browser persistence model as the
  existing `fat_enabled`), and closes the dropdown. No server round-trip.
- With **one** configured language the chip renders as a static label (no dropdown) —
  most installs are single-language and shouldn't grow a pointless menu.
- The plugin gets `->languageSwitcher(bool)` alongside the existing
  `->showToggle(bool)` so hosts can hide the chip entirely.

### Field-level presentation

- **Pinned fields** (`->translatable(target: 'hi')`, doc 01): show a tiny suffix
  badge inside the input wrapper with the pinned code (`HI`), so the user isn't
  surprised when the field ignores the header selection. Implemented as a CSS-only
  decoration off a `data-fat-pinned` attribute — no extra DOM listeners.
- **Focus hint**: on first focus of a translatable field per session, the existing
  overlay box briefly shows "Transliterating to اردو — press space after a word".
  Reuses `showMessage()`; dismissed on type; suppressed after the first time
  (sessionStorage). Makes the invisible feature discoverable.
- RTL correctness: when the effective target is RTL, applied text lives in an LTR
  input alongside Latin text. The overlay/messages must set `dir="auto"` on
  rendered target-script strings; the input itself is left alone (mixed-direction
  fields are the host's typographic call).

### How the frontend learns the language table

New render hook injected by `FilamentAutoTransliteratePlugin::register()` at
`HEAD_END` (next to the existing CSRF meta injection):

```html
<script>window.fatConfig = {
  languages: { ur: {label, native, rtl, pattern}, ... },  // Languages::forJs()
  defaultTarget: 'ur',
  learnEnabled: true
};</script>
```

- Injected at HEAD_END so it exists before the Filament-registered asset bundle
  executes, regardless of load order.
- The blade toggle view reads the same payload for its dropdown (via Alpine
  `x-data`), so blade and JS can never disagree about the language list.
- `translation-overlay.js` resolves the effective language per keystroke:
  field pin → `localStorage.fat_target_lang` → `fatConfig.defaultTarget`, and uses
  `languages[code].pattern` for already-in-script detection (doc 01).

### Accessibility

- Chip button: `aria-haspopup="listbox"`, `aria-expanded`, dropdown items
  `role="option"` + `aria-selected`; Escape closes (the overlay's global Escape
  handler must ignore events while the dropdown is open).
- Native names rendered with `lang="<code>"` and `dir` attributes so screen
  readers pick the right voice.
- The on/off toggle keeps its current `sr-only` label; extend it to announce the
  active language ("Inline transliteration on, Urdu").

## Files touched

| File | Change |
| ---- | ------ |
| `src/FilamentAutoTransliteratePlugin.php` | `fatConfig` HEAD_END hook; `->languageSwitcher()` |
| `resources/views/hooks/toggle.blade.php` | language chip + dropdown (Alpine, Tailwind — same idiom as current file) |
| `resources/js/translation-overlay.js` | `setTargetLang()/getTargetLang()`, effective-language resolution, focus hint |
| `resources/css/translation-overlay.css` | pinned-badge decoration, chip/dropdown fallback styles |
| `src/Macros/TranslatableMacro.php` | emit `data-fat-pinned` |
| tests | plugin hook renders config JSON; switcher hidden when configured off |

Manual test matrix (no JS test runner in this repo): single-language install (no
dropdown), multi-language switch mid-form, pinned field ignoring switcher, RTL
target in LTR form, dropdown keyboard navigation, state across Livewire navigation.
