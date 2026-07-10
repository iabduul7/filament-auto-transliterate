# Planning documents

Design plans for the next major iteration of Filament Auto Transliterate. Nothing in
this folder is implemented yet unless a document says so — these are blueprints,
written against the codebase as of v0.1.0.

| Doc | Covers |
| --- | ------ |
| [01-multi-language.md](01-multi-language.md) | First-class language→language transliteration: the language registry, per-language script detection, per-field target pinning, API/config changes |
| [02-self-improvement.md](02-self-improvement.md) | How the package learns from user corrections and gets better over time |
| [03-language-switch-ui.md](03-language-switch-ui.md) | UI for presenting and switching the target language (header dropdown, field pins, RTL, a11y) |
| [04-free-tier-quality.md](04-free-tier-quality.md) | Getting better transliteration out of free services: corrections-first cache, local dictionaries, candidates, circuit breaker, client-side de-dup |
| [05-monetization.md](05-monetization.md) | Proposed free vs. paid tier split |

Suggested implementation order: **01 → 03 → 04 → 02**. The language registry (01)
is the foundation the UI (03) and quality work (04) build on; the learning loop (02)
touches everything, so it lands last. The monetization split (05) informs where
feature flags go but requires no code up front.
