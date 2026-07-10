# 05 — Free vs. paid tier

## Principles

1. **The core promise is never paywalled.** Type a Roman word, get it in the target
   script, in any supported language — that loop (and its correctness guarantees)
   stays MIT-licensed and free forever. It's what earns adoption, stars, and trust
   in the Filament ecosystem.
2. **Charge for team, scale, and administration** — the things a company with a
   data-entry floor needs and an individual developer doesn't.
3. **Never remove something that already shipped free.** The Microsoft/Google
   provider classes are in the free package today; they stay there. Paid is
   additive.
4. Free tier must be genuinely complete, not crippled — a hobbyist should never hit
   a wall; a 10-seat data-entry operation should quickly *want* the Pro features.

## The split

### Free (this repo, MIT)

| Area | Included |
| ---- | -------- |
| Transliteration | All languages in the registry (doc 01), Google Input Tools provider, per-field pins |
| Translation mode | Free providers (MyMemory, LibreTranslate) **and** bring-your-own-key Microsoft/Google (already shipped) |
| UI | Header toggle + language switcher (doc 03), inline overlay, spinners |
| Quality | Permanent DB cache, local JSON dictionaries (both modes), circuit breaker, client-side de-dup/negative cache (doc 04) |
| Learning | Learn-from-correction, single install, applied automatically (doc 02) |
| Ops | `/stats` + `/provider-status` JSON endpoints, install command |

### Pro (separate private package, e.g. `filament-auto-transliterate-pro`)

Ranked by expected willingness-to-pay:

1. **Glossary & corrections manager** — a Filament resource over the cache table:
   review/edit/delete learned corrections, approve a moderation queue, bulk edit,
   search by language/source/confidence. (The #1 ask the moment a wrong correction
   gets learned on a shared install.)
2. **Team glossaries** — per-user attribution on corrections, approval workflow
   (corrections apply per-user until approved, then install-wide), export/import
   in the free dictionary JSON format, sync between environments/installs.
3. **Bulk transliteration** — table bulk action + artisan command to transliterate
   existing columns of records (with dry-run + review screen). Turns the package
   from an input helper into a migration tool for legacy Latin-typed data.
4. **Candidate picker UI** — the alternatives dropdown on applied words
   (plumbing is free, doc 04; the polished picker + `user_pick` learning is Pro).
5. **Analytics dashboard** — Filament widgets: conversion volume, cache/learn hit
   rates, provider health & latency, per-language usage, cost-avoided estimates.
6. **Premium provider integrations** — DeepL, Google Cloud Translation v3
   glossaries, Azure custom translators; per-field provider routing.
7. **Policy & roles** — who may switch languages, who may teach corrections,
   per-panel/per-tenant language sets (multi-tenancy aware).
8. **Priority support + guaranteed compatibility window** for new Filament majors.

### Deliberately free forever (tempting to paywall — don't)

- Additional languages. Paywalling languages punishes exactly the underserved
  scripts this package exists for, and the registry is just config anyway.
- The learning loop itself. It's the product's moat *because* every install has it;
  Pro monetizes managing it at team scale, not having it.
- Rate limits/caps in the free package. Artificial caps on a self-hosted package
  are hostile and trivially forked around.

## Mechanics

- **Two packages, one core.** Pro is a separate private composer package that
  depends on the free one and registers its own plugin/resources. No license checks
  in the free package, no phone-home.
- **Distribution**: AnyStack or Lemon Squeezy license + private Composer repo — the
  established pattern for paid Filament plugins (also gets listing on
  filamentphp.com/plugins as a paid plugin).
- **Pricing shape** (validate later): per-project license with unlimited seats,
  ~$49–79/project/year, in line with the Filament paid-plugin market; a
  lifetime/unlimited tier for agencies.
- **Sequencing**: ship docs 01–04 free first and grow usage; build Pro item 1
  (glossary manager) as the first paid release, since docs 02's data model makes it
  a mostly-UI effort.

## Repo hygiene enabling the split

- Keep everything Pro needs behind clean extension points that already exist:
  `provider_map` (custom providers), the cache model (glossary manager reads it),
  events. Add a few domain events in the free core while implementing docs 01–04 —
  `TranslationCached`, `CorrectionLearned` — so Pro can hook without patching.
- Nothing in the free codebase should reference Pro; discovery is one README
  section and the plugin listing.
