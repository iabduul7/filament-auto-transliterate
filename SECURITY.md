# Security Policy

## Reporting a vulnerability

Please do **not** open a public issue for security problems.

Use GitHub private vulnerability reporting:
<https://github.com/iabduul7/filament-auto-transliterate/security/advisories/new>

Include affected versions, a minimal reproduction, and the impact you expect. You can expect an acknowledgement within a few days; fixes are released as a patch version and credited in the changelog unless you prefer otherwise.

## Supported versions

| Version | Supported          |
| ------- | ------------------ |
| 0.2.x   | Yes                |
| < 0.2   | No                 |

## Security model

- The translation endpoint is an **auth-gated and throttled proxy** to third-party APIs (Google, Microsoft, LibreTranslate, MyMemory, ...). It must never be made public.
- Text typed into a `->translatable()` field **leaves your server** and is sent to whichever providers you configure. Use only providers you are comfortable sharing that text with, or restrict to the local dictionary providers.
- User input is **not logged** unless `log_requests` is explicitly enabled.
- Provider API keys live in your app's config/.env; they are never sent to the browser.
- Cached translations are stored in your own database table (`translation_cache`).
