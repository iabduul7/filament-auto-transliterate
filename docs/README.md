# Planning documents

Design plans for Filament Auto Transliterate, written against the codebase as of
v0.3.0. Status is tracked per document below.

| Doc | Covers | Status |
| --- | ------ | ------ |
| [01-multi-language.md](01-multi-language.md) | First-class language→language transliteration: the language registry, per-language script detection, per-field target pinning, API/config changes | ✅ implemented |
| [02-self-improvement.md](02-self-improvement.md) | How the package learns from user corrections and gets better over time | ✅ implemented, hardened in v0.3.0 (learn limits, `user_id`, atomic upsert, prune); follow-ups §"Beyond corrections" items 2–4 remain |
| [03-language-switch-ui.md](03-language-switch-ui.md) | UI for presenting and switching the target language (header dropdown, field pins, RTL, a11y) | ✅ implemented; keyboard a11y, JS-rendered pinned badge and the `fat:*` event API added in v0.3.0 |
| [04-free-tier-quality.md](04-free-tier-quality.md) | Getting better transliteration out of free services: corrections-first cache, local dictionaries, candidates, circuit breaker, client-side de-dup | ✅ implemented; v0.3.0 adds the miss/error taxonomy, breaker-on-errors-only, safer fallback and provider hardening (candidate-picker UI remains) |
| [05-monetization.md](05-monetization.md) | Proposed free vs. paid tier split | 📋 proposal — no code intended in this repo |

Implementation deviations from the specs are noted in the CHANGELOG's v0.2.0
section (the shipped language set was trimmed to ten defaults, see doc 01); one deliberate deferral: the per-mode `api_timeout` split suggested in
doc 04 was left out (single global timeout kept) pending a config-shape decision.
