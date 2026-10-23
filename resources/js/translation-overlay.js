/**
 * Filament Auto Transliterate — inline, as-you-type overlay.
 *
 * Watches inputs tagged with `data-fat-translatable="true"`. On space (and on
 * Enter in a textarea, Tab, and blur) the last word is sent to the package
 * endpoint and the returned target-script text is written back inline. Toggle
 * on/off via the header button (state persists in localStorage). Framework-free
 * so it can ship as a prebuilt asset.
 *
 * Reads `window.fatConfig` (injected by the plugin at HEAD_END — see
 * FilamentAutoTransliteratePlugin) for the language table, the server default
 * target language, and the learn-from-correction kill switch. Every lookup
 * against it is defensive: the overlay must keep working (single-language,
 * Urdu-default behaviour) even if that global is missing entirely.
 *
 * Paste is deliberately NOT converted: pasted text is the user's explicit
 * content, and converting it would be surprising (and expensive).
 *
 * Per-field config (`data-fat-config` JSON): endpoint, learnEndpoint,
 * targetLang, targetPinned, mode, minLength, maxLength and optionally
 * `apiTimeout` (ms, default 8000). Fields without an `endpoint` are skipped.
 *
 * Public surface: window.FilamentAutoTransliterate
 *   .isEnabled, .toggleEnabled(bool), .setTargetLang(code), .getTargetLang()
 *
 * Events (CustomEvents dispatched on `window`; payload in `event.detail`):
 *   fat:ready             {}                                  instance constructed
 *   fat:applied           {input, original, applied, targetLang, mode}
 *   fat:error             {input, status}                     status 0 = network/abort
 *   fat:toggled           {enabled}
 *   fat:language-changed  {code}
 *
 * Calling `refresh()` re-scans the DOM (used when the bundle is re-evaluated by
 * wire:navigate / SPA navigation, in which case the existing instance is reused).
 */

const DEFAULT_TIMEOUT_MS = 8000;
const COOLDOWN_RATE_MS = 15000;
const COOLDOWN_AUTH_MS = 30000;
const COOLDOWN_ERROR_MS = 5000;
const SPINNER_SVG =
  '<svg class="fat-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="fat-spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';

// localStorage / sessionStorage can throw (privacy modes, blocked storage).
function storageGet(key, session = false) {
  try {
    return (session ? window.sessionStorage : window.localStorage).getItem(key);
  } catch {
    return null;
  }
}

function storageSet(key, value, session = false) {
  try {
    (session ? window.sessionStorage : window.localStorage).setItem(key, value);
  } catch {
    // Storage unavailable — state simply isn't persisted.
  }
}

function emit(name, detail = {}) {
  try {
    window.dispatchEvent(new CustomEvent(name, { detail }));
  } catch {
    // Never let an event dispatch break typing.
  }
}

class FilamentAutoTransliterate {
  constructor() {
    this.overlay = null;
    this.activeInput = null;
    this.isEnabled = storageGet("fat_enabled") === "true";
    this.isApplying = false;
    this.debug = false;

    // Bounded FIFO cache of successful conversions: `mode:lang:word` -> text.
    this.wordCache = new Map();
    this.wordCacheLimit = 500;

    // In-field loading spinners, tracked per Filament input wrapper so
    // concurrent requests (rapid space presses, or different fields) never clear
    // each other's spinner. host element -> { el, count }.
    this.spinners = new Map();

    // Bumped when the feature is toggled off; in-flight requests started under an
    // older generation must not touch spinners/overlay/field when they finish.
    this.generation = 0;

    // In-flight request de-duplication: `mode:lang:word` -> Promise. Concurrent
    // requests for the same key (rapid space-space on the same word, or the same
    // word in two fields) share one network call.
    this.pendingRequests = new Map();

    // Session negative cache: `mode:lang:word` keys that are known to return "no
    // conversion", so retyping the same word doesn't re-request. FIFO-bounded so
    // an unbounded session can't grow this without limit.
    this.negativeCache = new Map();
    this.negativeCacheLimit = 500;

    // Cool-down (429 / auth / transient errors): skip requests for a window and
    // surface the message only once per cool-down, not once per skipped request.
    this.cooldownUntil = 0;
    this.cooldownKind = "rate";
    this.cooldownMessageShown = false;

    // Correction detector (doc 02): input element -> the most recent applied
    // conversion on that field, used to detect the user immediately fixing our
    // output. WeakMap so replaced/removed inputs (Livewire morphs) don't leak.
    this.correctionRecords = new WeakMap();

    // Whether the focus overlay is currently showing the first-focus hint
    // (as opposed to a loading/error message) — used to dismiss it on type.
    this.hintActive = false;
    this.hintShownInMemory = false;

    this.messageTimer = null;
    this.scanTimer = null;
    this.repositionQueued = false;
    this.warnedMissingConfig = false;

    // keydown(space) -> input de-dup, and Tab/Enter keydown -> blur de-dup.
    this.spaceKeydown = null;
    this.recentKeyConvert = null;

    this.handleFocus = this.handleFocus.bind(this);
    this.handleBlur = this.handleBlur.bind(this);
    this.handleDelegatedKeydown = this.handleDelegatedKeydown.bind(this);
    this.handleDelegatedInput = this.handleDelegatedInput.bind(this);
    this.handleGlobalKeydown = this.handleGlobalKeydown.bind(this);
    this.queueReposition = this.queueReposition.bind(this);

    this.init();
  }

  init() {
    this.createOverlay();
    this.observeInputs();

    document.addEventListener("keydown", this.handleGlobalKeydown);
    document.addEventListener("keydown", this.handleDelegatedKeydown, true);
    // Fallback trigger for IMEs / mobile keyboards that report `Unidentified`
    // (keyCode 229) on keydown but still emit `input` with data === " ".
    document.addEventListener("input", this.handleDelegatedInput, true);

    window.addEventListener("scroll", this.queueReposition, true);
    window.addEventListener("resize", this.queueReposition);

    // Re-scan after Livewire swaps DOM (modals, dynamic fields).
    document.addEventListener("livewire:navigated", () => this.scheduleScan());
    const hook = () => {
      const lw = window.Livewire;
      if (!lw || typeof lw.hook !== "function") return;
      lw.hook("morph.added", () => this.scheduleScan());
      lw.hook("morph.updated", () => this.scheduleScan());
    };
    if (window.Livewire) hook();
    else document.addEventListener("livewire:init", hook, { once: true });
  }

  // Called instead of constructing a second instance when the bundle is
  // re-evaluated (wire:navigate / SPA re-eval): re-scan and let listeners re-sync.
  refresh() {
    this.observeInputs();
    emit("fat:ready");
  }

  log(message) {
    if (this.debug) console.info(`[FilamentAutoTransliterate] ${message}`);
  }

  createOverlay() {
    this.overlay = document.createElement("div");
    this.overlay.className = "fat-overlay";
    this.overlay.setAttribute("role", "status");
    this.overlay.setAttribute("aria-live", "polite");
    this.overlay.setAttribute("aria-atomic", "true");
    document.body.appendChild(this.overlay);
  }

  // Debounced rescan: N morph events in one burst produce a single sweep.
  scheduleScan() {
    if (this.scanTimer) return;
    this.scanTimer = setTimeout(() => {
      this.scanTimer = null;
      this.observeInputs();
    }, 50);
  }

  observeInputs() {
    // wire:navigate swaps <body>, detaching the overlay: re-attach it.
    if (this.overlay && !this.overlay.isConnected) document.body.appendChild(this.overlay);

    // Drop spinner entries whose host wrapper was detached by a Livewire morph,
    // so a replaced .fi-input-wrp can't be left with a stuck `fat-loading-host`
    // class (and stale `position: relative`).
    this.spinners.forEach((entry, host) => {
      if (!document.contains(host)) {
        entry.el.remove();
        this.spinners.delete(host);
      }
    });

    document
      .querySelectorAll('[data-fat-translatable="true"]')
      .forEach((input) => {
        // Same bound function refs: addEventListener is idempotent.
        input.addEventListener("focus", this.handleFocus);
        input.addEventListener("blur", this.handleBlur);

        if (!input.hasAttribute("dir")) input.setAttribute("dir", "auto");

        this.ensurePinnedBadge(input);
      });
  }

  // Pinned-field badge: a tiny chip in the Filament input wrapper showing the
  // pinned target language code. Skipped when the input has no wrapper.
  ensurePinnedBadge(input) {
    const code = input.dataset.fatPinned;
    const host = input.closest(".fi-input-wrp");
    if (!code || !host) return;

    let badge = host.querySelector(":scope > .fat-pinned-badge");
    if (!badge) {
      badge = document.createElement("span");
      badge.className = "fat-pinned-badge";
      badge.setAttribute("aria-hidden", "true");
      host.appendChild(badge);
    }
    if (badge.textContent !== code) badge.textContent = code;
    host.classList.add("fat-badge-host");
  }

  // ---------------------------------------------------------------------
  // Language config (window.fatConfig)
  // ---------------------------------------------------------------------

  getFatConfig() {
    return window.fatConfig || null;
  }

  getLanguages() {
    const fatConfig = this.getFatConfig();
    return (fatConfig && fatConfig.languages) || {};
  }

  learnEnabled() {
    const fatConfig = this.getFatConfig();
    // Default to enabled when fatConfig hasn't loaded yet — the actual POST is
    // still gated by config.learnEndpoint being present on the field.
    return !fatConfig || fatConfig.learnEnabled !== false;
  }

  // Effective target language for a field, resolved in priority order:
  //   1. field pin (`->translatable(target: 'hi')`)
  //   2. the user's header selection (localStorage), if still a valid language
  //   3. the server default (fatConfig.defaultTarget)
  //   4. the field's own baked-in targetLang (data-fat-config fallback)
  resolveTargetLang(config) {
    if (config.targetPinned) return config.targetLang;

    const languages = this.getLanguages();
    const stored = storageGet("fat_target_lang");
    if (stored && Object.prototype.hasOwnProperty.call(languages, stored)) {
      return stored;
    }

    const fatConfig = this.getFatConfig();
    if (fatConfig && fatConfig.defaultTarget) return fatConfig.defaultTarget;

    return config.targetLang;
  }

  // Public API for the header language switcher (resources/views/hooks/toggle.blade.php).
  setTargetLang(code) {
    if (!code) return;
    storageSet("fat_target_lang", code);
    emit("fat:language-changed", { code });
  }

  getTargetLang() {
    const languages = this.getLanguages();
    const stored = storageGet("fat_target_lang");
    if (stored && Object.prototype.hasOwnProperty.call(languages, stored)) {
      return stored;
    }

    const fatConfig = this.getFatConfig();
    return (fatConfig && fatConfig.defaultTarget) || null;
  }

  // RegExp for "already in the target script", built from the language table's
  // pattern source (e.g. "[؀-ۿ]"). Falls back to the Arabic/Urdu block when the
  // language is unknown or fatConfig hasn't loaded.
  getScriptPattern(targetLang) {
    const source = this.getLanguages()[targetLang]?.pattern;
    if (source) {
      try {
        return new RegExp(source);
      } catch {
        // Malformed pattern from config — fall through to the default below.
      }
    }
    return /[؀-ۿ]/;
  }

  // Parsed per-field config, or null (with a one-time warning) when the field
  // has no usable config — never fetch `/undefined`.
  getConfig(input) {
    let config = null;
    try {
      config = JSON.parse(input.dataset.fatConfig || "null");
    } catch {
      config = null;
    }

    if (!config || typeof config !== "object" || !config.endpoint) {
      if (!this.warnedMissingConfig) {
        this.warnedMissingConfig = true;
        console.warn(
          "[FilamentAutoTransliterate] A translatable field has a missing or invalid data-fat-config (no endpoint); skipping it.",
        );
      }
      return null;
    }

    if (!config.mode) config.mode = "transliterate";
    return config;
  }

  // ---------------------------------------------------------------------
  // Triggers
  // ---------------------------------------------------------------------

  isTranslatableField(target) {
    return (
      (target instanceof HTMLInputElement ||
        target instanceof HTMLTextAreaElement) &&
      target.dataset.fatTranslatable === "true"
    );
  }

  handleDelegatedKeydown(e) {
    const target = e.target;
    if (!this.isTranslatableField(target)) return;

    // Any keypress in the field dismisses the first-focus hint.
    if (this.hintActive && this.activeInput === target) {
      this.hideOverlay();
    }

    if (!this.isEnabled || this.isApplying) return;

    // IME composition / "Unidentified" keydown: the `input` fallback handles it.
    if (e.isComposing || e.keyCode === 229) return;
    if (e.ctrlKey || e.altKey || e.metaKey) return;

    const isSpace = e.key === " " || e.code === "Space";
    if (isSpace) {
      // Remember that keydown saw this space so the following `input` event
      // doesn't convert a second time (repeat included).
      this.spaceKeydown = { input: target, t: Date.now() };
      if (e.repeat) return;
      this.convertPending(target, {});
      return;
    }

    if (e.repeat) return;

    if (e.key === "Enter") {
      this.noteKeyConvert(target);
      if (target instanceof HTMLTextAreaElement) {
        this.convertPending(target, {});
      } else {
        // Single-line input: Enter submits the form, so only a cached
        // conversion (synchronous) can land before the submit.
        this.convertPending(target, { cacheOnly: true });
      }
    } else if (e.key === "Tab") {
      this.noteKeyConvert(target);
      this.convertPending(target, {});
    }
  }

  handleDelegatedInput(e) {
    // Our own replacement (execCommand / dispatched event) — never re-trigger.
    if (this.isApplying) return;

    const target = e.target;
    if (!this.isTranslatableField(target)) return;

    const seen = this.spaceKeydown;
    if (seen && seen.input === target) {
      this.spaceKeydown = null;
      if (Date.now() - seen.t < 500) return; // keydown path already handled it
    }

    if (!this.isEnabled || e.isComposing) return;

    if (e.inputType === "insertText" && e.data === " ") {
      this.convertPending(target, { afterSpace: true });
    }
  }

  noteKeyConvert(input) {
    this.recentKeyConvert = { input, t: Date.now() };
  }

  // ---------------------------------------------------------------------
  // Token extraction / conversion
  // ---------------------------------------------------------------------

  caretIndex(input) {
    try {
      if (typeof input.selectionStart === "number") return input.selectionStart;
    } catch {
      // Input types without selection support (email/number).
    }
    return input.value.length;
  }

  // Split `raw` into leading punctuation, the word, and trailing punctuation.
  splitToken(raw) {
    const lead = (raw.match(/^[\p{P}\p{S}]+/u) || [""])[0];
    const rest = raw.slice(lead.length);
    const trail = (rest.match(/[\p{P}\p{S}]+$/u) || [""])[0];
    const core = rest.slice(0, rest.length - trail.length);
    return { lead, core, trail };
  }

  // Whether a token is worth sending at all. Skipped tokens are NOT negative
  // cached: they're filtered locally, deterministically.
  isConvertible(raw, core, config, targetLang) {
    if (!core) return false;
    if (!/\p{L}/u.test(core)) return false; // no letters / pure digits
    if (raw.includes("@") || raw.includes("://")) return false;
    if (core.length < (config.minLength ?? 0)) return false;
    if (config.maxLength && core.length > config.maxLength) return false;
    if (this.getScriptPattern(targetLang).test(core)) return false;
    return true;
  }

  // Convert the word immediately before the caret. `afterSpace`: the space has
  // already been inserted (input-event path). `cacheOnly`: never go to network.
  // `skipCorrection`: caller (blur) already ran the correction check.
  async convertPending(input, opts) {
    const { afterSpace = false, cacheOnly = false, skipCorrection = false } = opts;
    if (!this.isEnabled || this.isApplying) return;

    const config = this.getConfig(input);
    if (!config) return;

    // Trigger (b): just before the next conversion is applied in this field,
    // check whether the previous applied word was hand-corrected, then expire
    // the record — it never survives a further conversion on the same field.
    if (!skipCorrection) {
      this.checkForCorrection(input, true);
      this.correctionRecords.delete(input);
    }

    const targetLang = this.resolveTargetLang(config);
    const cursor = this.caretIndex(input);
    let before = input.value.slice(0, cursor);
    if (afterSpace && /\s$/.test(before)) before = before.slice(0, -1);

    const match = before.match(/(\S+)$/);
    if (!match) return;

    const raw = match[1];
    const parts = this.splitToken(raw);
    if (!this.isConvertible(raw, parts.core, config, targetLang)) return;

    const word = parts.core;
    const cacheKey = `${config.mode}:${targetLang}:${word}`;
    const meta = { targetLang, mode: config.mode };

    // Cheap local checks first so cached hits never flash a spinner.
    if (this.wordCache.has(cacheKey)) {
      this.applyInline(input, raw, parts, this.wordCache.get(cacheKey), meta);
      return;
    }
    if (cacheOnly) return;
    if (this.negativeCache.has(cacheKey)) return;
    if (this.isCoolingDown()) return;

    const gen = this.generation;
    const handle = this.startLoading(input, config.mode);
    let result;
    try {
      result = await this.fetchTranslationDeduped(
        cacheKey,
        word,
        config,
        targetLang,
        input,
      );
    } catch (error) {
      this.log(`request exception (${error.message})`);
      result = { ok: false, reason: "error", status: 0 };
    } finally {
      this.stopLoading(handle);
    }

    // Toggled off (or off and on again) while in flight: don't mutate the field
    // or surface any message — honour the toggle.
    if (!this.isEnabled || gen !== this.generation) return;

    if (result.ok) {
      this.rememberWord(cacheKey, result.translated);
      this.applyInline(input, raw, parts, result.translated, meta);
      return;
    }

    if (
      result.reason === "rateLimited" ||
      result.reason === "auth" ||
      result.reason === "error"
    ) {
      this.log(`request failed (${result.reason}, status=${result.status ?? "?"})`);
      this.showCooldownMessageOnce(input);
    }
    // reason === "miss": already recorded in the negative cache; leave the
    // typed word untouched, no noise.
  }

  // Shares one in-flight promise across concurrent requests for the same
  // `mode:lang:word` key (doc 04 client-side de-duplication).
  fetchTranslationDeduped(cacheKey, word, config, targetLang, input) {
    const existing = this.pendingRequests.get(cacheKey);
    if (existing) return existing;

    const promise = this.performFetch(word, config, targetLang, cacheKey, input).finally(
      () => this.pendingRequests.delete(cacheKey),
    );
    this.pendingRequests.set(cacheKey, promise);
    return promise;
  }

  // fetch with an abort timeout. Resolves to a result object; never rejects for
  // HTTP/network problems (those are transient and must not be negative cached).
  async performFetch(word, config, targetLang, cacheKey, input) {
    const timeoutMs =
      Number(config.apiTimeout) > 0 ? Number(config.apiTimeout) : DEFAULT_TIMEOUT_MS;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    let response;
    try {
      response = await fetch(config.endpoint, {
        method: "POST",
        headers: this.requestHeaders(),
        signal: controller.signal,
        body: JSON.stringify({
          text: word,
          target_lang: targetLang,
          mode: config.mode,
        }),
      });
    } catch {
      // Network failure or timeout abort: transient.
      this.beginCooldown(COOLDOWN_ERROR_MS, "error");
      emit("fat:error", { input, status: 0 });
      return { ok: false, reason: "error", status: 0 };
    } finally {
      clearTimeout(timer);
    }

    if (!response.ok) {
      const status = response.status;
      emit("fat:error", { input, status });

      if (status === 429) {
        this.beginCooldown(COOLDOWN_RATE_MS, "rate");
        return { ok: false, reason: "rateLimited", status };
      }
      if (status === 401 || status === 403 || status === 419) {
        this.beginCooldown(COOLDOWN_AUTH_MS, "auth");
        return { ok: false, reason: "auth", status };
      }
      if (status === 422) {
        // The server rejected this input outright: a hard miss.
        this.rememberNegative(cacheKey);
        return { ok: false, reason: "miss", status };
      }
      // 5xx and anything else: transient, never negative cached.
      this.beginCooldown(COOLDOWN_ERROR_MS, "error");
      return { ok: false, reason: "error", status };
    }

    let data;
    try {
      data = await response.json();
    } catch {
      this.beginCooldown(COOLDOWN_ERROR_MS, "error");
      emit("fat:error", { input, status: response.status });
      return { ok: false, reason: "error", status: response.status };
    }

    if (data && data.success && data.translated) {
      return { ok: true, translated: data.translated };
    }

    // 200 flagged transient: every provider errored (or its breaker is open),
    // so this is an outage, not a miss — never negative cache it.
    if (data && data.transient === true) {
      this.beginCooldown(COOLDOWN_ERROR_MS, "error");
      return { ok: false, reason: "error", status: response.status };
    }

    // 200 with no usable conversion: a genuine miss.
    this.rememberNegative(cacheKey);
    return { ok: false, reason: "miss" };
  }

  getCsrfHeaders() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.content;
    if (meta) return { "X-CSRF-TOKEN": meta };

    try {
      const entry = document.cookie
        .split("; ")
        .find((row) => row.startsWith("XSRF-TOKEN="));
      if (entry) {
        // Laravel expects the (encrypted) cookie value in X-XSRF-TOKEN.
        return {
          "X-XSRF-TOKEN": decodeURIComponent(entry.slice("XSRF-TOKEN=".length)),
        };
      }
    } catch {
      // Cookie unreadable / malformed — send no token.
    }
    return {};
  }

  // Re-read per request: the token rotates (session regeneration, Livewire).
  requestHeaders() {
    return {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
      ...this.getCsrfHeaders(),
    };
  }

  rememberWord(key, value) {
    this.wordCache.delete(key); // refresh insertion order
    this.wordCache.set(key, value);
    if (this.wordCache.size > this.wordCacheLimit) {
      this.wordCache.delete(this.wordCache.keys().next().value);
    }
  }

  rememberNegative(key) {
    if (this.negativeCache.has(key)) return;
    this.negativeCache.set(key, true);
    if (this.negativeCache.size > this.negativeCacheLimit) {
      const oldest = this.negativeCache.keys().next().value;
      this.negativeCache.delete(oldest);
    }
  }

  isCoolingDown() {
    return Date.now() < this.cooldownUntil;
  }

  beginCooldown(ms = COOLDOWN_RATE_MS, kind = "rate") {
    this.cooldownUntil = Date.now() + ms;
    this.cooldownKind = kind;
    this.cooldownMessageShown = false;
  }

  showCooldownMessageOnce(input) {
    if (this.cooldownMessageShown) return;
    this.cooldownMessageShown = true;

    const messages = {
      rate: "Too many requests — pausing for a moment.",
      auth: "Session expired, reload the page.",
      error: "Transliteration unavailable.",
    };
    this.showMessage(input, messages[this.cooldownKind] || messages.error);
  }

  // ---------------------------------------------------------------------
  // Applying the result
  // ---------------------------------------------------------------------

  applyInline(input, rawToken, parts, translatedWord, meta = {}) {
    const cursor = this.caretIndex(input);
    const before = input.value.slice(0, cursor);

    // Find the last WHOLE-TOKEN occurrence of the token (bounded by whitespace or
    // the string edges). This avoids replacing the word where it appears as a
    // substring of another word — which a slow/overlapping request could
    // otherwise do after the surrounding text has shifted.
    const start = this.lastWholeWordIndex(before, rawToken);
    if (start === -1) return false;

    const end = start + rawToken.length;
    const replacement = parts.lead + translatedWord + parts.trail;
    const newCaret = cursor + (replacement.length - rawToken.length);

    this.replaceRange(input, start, end, replacement, newCaret);

    // Snapshot for the correction detector (doc 02) — latest per field only,
    // and only kept when learning is enabled (no point tracking corrections
    // that will never be persisted).
    if (this.learnEnabled() && meta.targetLang) {
      this.correctionRecords.set(input, {
        original: parts.core,
        applied: translatedWord,
        appliedToken: replacement,
        lead: parts.lead,
        trail: parts.trail,
        targetLang: meta.targetLang,
        mode: meta.mode,
        valueAfterApply: input.value,
        blurred: false,
      });
    } else {
      this.correctionRecords.delete(input);
    }

    emit("fat:applied", {
      input,
      original: parts.core,
      applied: translatedWord,
      targetLang: meta.targetLang,
      mode: meta.mode,
    });
    return true;
  }

  // Replace [start, end) with `text`. Prefers execCommand('insertText') on a
  // focused field (keeps the native undo stack and fires real input events);
  // falls back to assigning the value and dispatching `input`.
  replaceRange(input, start, end, text, newCaret) {
    const oldValue = input.value;
    const scrollTop = input.scrollTop;
    let done = false;

    this.isApplying = true;
    try {
      if (document.activeElement === input) {
        try {
          input.setSelectionRange(start, end);
          done = document.execCommand("insertText", false, text);
          if (done && input.value.slice(start, start + text.length) !== text) {
            // Truncated (e.g. maxlength) or otherwise not what we asked for:
            // restore and use the fallback so the result is deterministic.
            input.value = oldValue;
            done = false;
          }
        } catch {
          // setSelectionRange unsupported (email/number) or execCommand blocked.
          done = false;
        }
      }

      if (!done) {
        input.value = oldValue.slice(0, start) + text + oldValue.slice(end);
        input.dispatchEvent(new Event("input", { bubbles: true }));
      }

      try {
        input.setSelectionRange(newCaret, newCaret);
      } catch {
        // Input type without selection support — leave the caret alone.
      }
      if (input.scrollTop !== scrollTop) input.scrollTop = scrollTop;
    } finally {
      this.isApplying = false;
    }
  }

  // Index of the last occurrence of `word` in `text` that stands as a complete
  // token (preceded by whitespace or the start, followed by whitespace or the
  // end). Returns -1 if there is no such occurrence.
  lastWholeWordIndex(text, word) {
    let from = text.length;
    for (;;) {
      const idx = text.lastIndexOf(word, from);
      if (idx === -1) return -1;
      const before = idx === 0 ? "" : text[idx - 1];
      const afterPos = idx + word.length;
      const after = afterPos >= text.length ? "" : text[afterPos];
      const boundedBefore = before === "" || /\s/.test(before);
      const boundedAfter = after === "" || /\s/.test(after);
      if (boundedBefore && boundedAfter) return idx;
      from = idx - 1;
      if (from < 0) return -1;
    }
  }

  // ---------------------------------------------------------------------
  // Correction detector (doc 02) — "the user fixed our word"
  // ---------------------------------------------------------------------

  // `allowTrailing` is true at trigger (b) (just before the next conversion is
  // applied), where the user has already typed the start of the next word, so
  // the current token array may be longer than the snapshot. At trigger (a)
  // (blur) the lengths must match exactly.
  checkForCorrection(input, allowTrailing) {
    const record = this.correctionRecords.get(input);
    if (!record) return;

    const snapshotTokens = this.tokenize(record.valueAfterApply);
    let currentTokens = this.tokenize(input.value);

    if (allowTrailing && currentTokens.length > snapshotTokens.length) {
      currentTokens = currentTokens.slice(0, snapshotTokens.length);
    }

    if (currentTokens.length !== snapshotTokens.length) return;

    let diffIndex = -1;
    let diffCount = 0;
    for (let i = 0; i < snapshotTokens.length; i++) {
      if (snapshotTokens[i] !== currentTokens[i]) {
        diffCount++;
        diffIndex = i;
        if (diffCount > 1) break;
      }
    }

    if (diffCount !== 1) return;

    const oldToken = snapshotTokens[diffIndex];
    const newToken = currentTokens[diffIndex];
    if (oldToken !== record.appliedToken) return;
    if (!newToken || newToken === oldToken) return;

    // Surrounding punctuation must be unchanged; only the word itself differs.
    if (!newToken.startsWith(record.lead) || !newToken.endsWith(record.trail)) return;
    if (newToken.length < record.lead.length + record.trail.length) return;
    const newCore = newToken.slice(
      record.lead.length,
      newToken.length - record.trail.length,
    );

    if (newCore.length < 2) return;
    if (newCore === record.applied) return;
    if (!this.getScriptPattern(record.targetLang).test(newCore)) return;

    this.acceptCorrection(input, record, newCore);
  }

  tokenize(value) {
    return value.split(/\s+/).filter(Boolean);
  }

  acceptCorrection(input, record, correctedWord) {
    this.log(`learned correction: ${record.original} -> ${correctedWord}`);

    const cacheKey = `${record.mode}:${record.targetLang}:${record.original}`;
    this.rememberWord(cacheKey, correctedWord);
    this.correctionRecords.delete(input);

    const config = this.getConfig(input);
    if (!config || !config.learnEndpoint) return;
    if (this.isCoolingDown()) return;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), DEFAULT_TIMEOUT_MS);

    // Fire-and-forget: never surfaces UI, failures are debug-logged only.
    fetch(config.learnEndpoint, {
      method: "POST",
      headers: this.requestHeaders(),
      signal: controller.signal,
      body: JSON.stringify({
        original: record.original,
        corrected: correctedWord,
        target_lang: record.targetLang,
        mode: record.mode,
      }),
    })
      .then((response) => {
        if (response.status === 429) {
          this.beginCooldown(COOLDOWN_RATE_MS, "rate");
        } else if ([401, 403, 419].includes(response.status)) {
          this.beginCooldown(COOLDOWN_AUTH_MS, "auth");
        }
      })
      .catch((error) => this.log(`learn request failed (${error.message})`))
      .finally(() => clearTimeout(timer));
  }

  // ---------------------------------------------------------------------
  // Spinners / overlay
  // ---------------------------------------------------------------------

  // Begin the loading indicator for a field. Prefers a compact spinner inside
  // the field's trailing edge; falls back to the below-field box for hosts whose
  // inputs aren't wrapped in Filament's `.fi-input-wrp`. Reference-counted per
  // host so overlapping requests on the same field don't stack or prematurely
  // clear the spinner. Returns a handle for stopLoading().
  startLoading(input, mode) {
    const gen = this.generation;
    const host = input.closest(".fi-input-wrp");
    if (!host) {
      this.showLoading(input, mode);
      return { host: null, entry: null, gen };
    }

    const existing = this.spinners.get(host);
    if (existing) {
      existing.count += 1;
      return { host, entry: existing, gen };
    }

    host.classList.add("fat-loading-host");
    const el = document.createElement("span");
    el.className = "fat-field-spinner";
    el.setAttribute("aria-hidden", "true");
    el.innerHTML = SPINNER_SVG;
    host.appendChild(el);

    const entry = { el, count: 1 };
    this.spinners.set(host, entry);
    return { host, entry, gen };
  }

  // End the loading indicator for a handle from startLoading(). Only decrements
  // the exact entry it incremented, so a request that outlived a toggle-off (or
  // a morph) can never decrement a NEW entry for the same host.
  stopLoading(handle) {
    if (!handle || handle.gen !== this.generation) return;

    const { host, entry } = handle;
    if (host && entry && this.spinners.get(host) === entry) {
      entry.count -= 1;
      if (entry.count <= 0) {
        entry.el.remove();
        host.classList.remove("fat-loading-host");
        this.spinners.delete(host);
      }
    }

    // Only hide the below-field box if it is showing the loading state (fallback
    // path) — never clobber an error message (which manages its own dismiss).
    if (!host && this.overlay.classList.contains("is-loading")) {
      this.hideOverlay();
    }
  }

  // Hard-clear every spinner (e.g. when the feature is toggled off mid-request).
  removeAllSpinners() {
    this.spinners.forEach((entry, host) => {
      entry.el.remove();
      host.classList.remove("fat-loading-host");
    });
    this.spinners.clear();
  }

  clearMessageTimer() {
    if (this.messageTimer) {
      clearTimeout(this.messageTimer);
      this.messageTimer = null;
    }
  }

  showLoading(input, mode) {
    this.clearMessageTimer();
    this.activeInput = input;
    this.overlay.classList.add("is-loading");
    this.overlay.classList.remove("is-error");
    this.hintActive = false;
    this.overlay.innerHTML = `<div class="fat-loading">${SPINNER_SVG}<span>${
      mode === "translate" ? "Translating..." : "Transliterating..."
    }</span></div>`;
    this.overlay.style.display = "block";
    this.positionOverlay(input);
  }

  // Brief, auto-dismissing notice. Text is fixed/computed (never user input) and
  // set via textContent, so no escaping is needed. Also used for the first-focus
  // language hint (doc 03) — `dir="auto"` on the message box so an embedded
  // target-script native name (e.g. "اردو") renders correctly either way.
  showMessage(input, message, kind = "error") {
    this.clearMessageTimer();
    this.activeInput = input;
    this.overlay.classList.remove("is-loading");
    this.overlay.classList.toggle("is-error", kind === "error");
    this.overlay.textContent = "";

    const box = document.createElement("div");
    box.className = "fat-message";
    box.setAttribute("dir", "auto");
    const span = document.createElement("span");
    span.textContent = message;
    box.appendChild(span);
    this.overlay.appendChild(box);

    this.overlay.style.display = "block";
    this.positionOverlay(input);
    this.messageTimer = setTimeout(() => {
      this.messageTimer = null;
      this.hideOverlay();
    }, 2500);
  }

  // First-focus-per-session hint, suppressed after the first showing via
  // sessionStorage (plus an in-memory flag for when storage is unavailable).
  // Reuses showMessage() so it looks and behaves like any other overlay notice;
  // `hintActive` lets a subsequent keypress dismiss it early.
  maybeShowFocusHint(input, targetLang, mode) {
    if (this.hintShownInMemory || storageGet("fat_hint_shown", true) === "true") {
      return;
    }
    this.hintShownInMemory = true;
    storageSet("fat_hint_shown", "true", true);

    const native = this.getLanguages()[targetLang]?.native || targetLang;
    const verb = mode === "translate" ? "Translating" : "Transliterating";
    this.showMessage(input, `${verb} to ${native} — press space after a word`, "info");
    this.hintActive = true;
  }

  hideOverlay() {
    this.clearMessageTimer();
    this.overlay.style.display = "none";
    this.overlay.classList.remove("is-error", "is-loading");
    this.activeInput = null;
    this.hintActive = false;
  }

  // The overlay is `position: fixed`, so coordinates are viewport-relative (no
  // scroll offsets). Aligned to the input's start edge (end edge in RTL),
  // clamped inside the viewport, flipped above the field when there's no room
  // below. Must be called while the overlay is displayed so it can be measured.
  positionOverlay(input) {
    const margin = 8;
    const rect = input.getBoundingClientRect();
    const vw = document.documentElement.clientWidth || window.innerWidth;
    const vh = window.innerHeight;
    const overlay = this.overlay;

    const width = Math.min(Math.max(rect.width, 300), Math.max(vw - margin * 2, 0));
    overlay.style.width = `${width}px`;

    const rtl = getComputedStyle(input).direction === "rtl";
    let left = rtl ? rect.right - width : rect.left;
    left = Math.max(margin, Math.min(left, vw - width - margin));

    const height = overlay.offsetHeight || 0;
    let top = rect.bottom + margin;
    if (height && top + height > vh - margin) {
      const above = rect.top - margin - height;
      top = above >= margin ? above : Math.max(margin, vh - height - margin);
    }

    overlay.style.top = `${top}px`;
    overlay.style.left = `${left}px`;
  }

  queueReposition() {
    if (this.repositionQueued) return;
    if (!this.activeInput || this.overlay.style.display !== "block") return;
    this.repositionQueued = true;
    requestAnimationFrame(() => {
      this.repositionQueued = false;
      if (this.activeInput && this.overlay.style.display === "block") {
        this.positionOverlay(this.activeInput);
      }
    });
  }

  handleGlobalKeydown(e) {
    if (e.key === "Escape" && this.overlay.style.display === "block") {
      e.preventDefault();
      this.hideOverlay();
    }
  }

  handleFocus(e) {
    if (
      this.activeInput === e.target &&
      this.overlay.style.display === "block"
    ) {
      this.positionOverlay(e.target);
    }

    if (this.isEnabled) {
      const config = this.getConfig(e.target);
      if (config) {
        this.maybeShowFocusHint(e.target, this.resolveTargetLang(config), config.mode);
      }
    }
  }

  handleBlur(e) {
    const target = e.target;

    // Trigger (a): check for a hand-fixed word as the field loses focus. The
    // record is kept (flagged) so the next conversion can still expire it.
    this.checkForCorrection(target, false);
    const record = this.correctionRecords.get(target);
    if (record) record.blurred = true;

    // Convert the pending last word, unless Tab/Enter just did.
    const recent = this.recentKeyConvert;
    const handledByKey =
      recent && recent.input === target && Date.now() - recent.t < 300;
    if (this.isEnabled && !this.isApplying && !handledByKey) {
      this.convertPending(target, { skipCorrection: true });
    }

    setTimeout(() => {
      if (this.activeInput === target) this.hideOverlay();
    }, 200);
  }

  toggleEnabled(enabled) {
    this.isEnabled = !!enabled;
    storageSet("fat_enabled", this.isEnabled ? "true" : "false");
    if (!this.isEnabled) {
      // Invalidate every in-flight request: their finally-blocks must not touch
      // spinners created after this point.
      this.generation += 1;
      this.hideOverlay();
      this.removeAllSpinners();
    }
    emit("fat:toggled", { enabled: this.isEnabled });
  }
}

// Singleton: if the bundle is evaluated again (wire:navigate / SPA re-eval),
// reuse the existing instance instead of registering a second set of document
// listeners.
function boot() {
  const existing = window.FilamentAutoTransliterate;
  if (existing && typeof existing.refresh === "function") {
    existing.refresh();
    return;
  }
  window.FilamentAutoTransliterate = new FilamentAutoTransliterate();
  // After assignment, so listeners can read window.FilamentAutoTransliterate.
  emit("fat:ready");
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", boot);
} else {
  boot();
}

export default FilamentAutoTransliterate;
