/**
 * Filament Auto Translate — inline, as-you-type overlay.
 *
 * Watches inputs tagged with `data-fat-translatable="true"`. On space, the last
 * word is sent to the package endpoint and the returned target-script text is
 * written back inline. Toggle on/off via the header button (state persists in
 * localStorage). Framework-free so it can ship as a prebuilt asset.
 *
 * Reads `window.fatConfig` (injected by the plugin at HEAD_END — see
 * FilamentAutoTransliteratePlugin) for the language table, the server default
 * target language, and the learn-from-correction kill switch. Every lookup
 * against it is defensive: the overlay must keep working (single-language,
 * Urdu-default behaviour) even if that global is missing entirely.
 */
class FilamentAutoTransliterate {
  constructor() {
    this.overlay = null;
    this.activeInput = null;
    this.wordCache = new Map();
    this.isEnabled = localStorage.getItem("fat_enabled") === "true";
    this.isApplying = false;
    this.debug = false;

    // In-field loading spinners, tracked per Filament input wrapper so
    // concurrent requests (rapid space presses, or different fields) never clear
    // each other's spinner. host element -> { el, count }.
    this.spinners = new Map();

    // In-flight request de-duplication: `mode:lang:word` -> Promise. Concurrent
    // requests for the same key (rapid space-space on the same word, or the same
    // word in two fields) share one network call.
    this.pendingRequests = new Map();

    // Session negative cache: `mode:lang:word` keys that are known to return "no
    // conversion", so retyping the same word doesn't re-request. FIFO-bounded so
    // an unbounded session can't grow this without limit.
    this.negativeCache = new Map();
    this.negativeCacheLimit = 500;

    // 429 cool-down: skip requests for a short window and surface the "pausing"
    // message only once per cool-down, not once per skipped request.
    this.cooldownUntil = 0;
    this.cooldownMessageShown = false;

    // Correction detector (doc 02): input element -> the most recent applied
    // conversion on that field, used to detect the user immediately fixing our
    // output. WeakMap so replaced/removed inputs (Livewire morphs) don't leak.
    this.correctionRecords = new WeakMap();

    // Whether the focus overlay is currently showing the first-focus hint
    // (as opposed to a loading/error message) — used to dismiss it on type.
    this.hintActive = false;

    this.handleFocus = this.handleFocus.bind(this);
    this.handleBlur = this.handleBlur.bind(this);
    this.handleDelegatedKeydown = this.handleDelegatedKeydown.bind(this);
    this.handleGlobalKeydown = this.handleGlobalKeydown.bind(this);

    this.init();
  }

  init() {
    this.createOverlay();
    this.observeInputs();

    document.addEventListener("keydown", this.handleGlobalKeydown);
    document.addEventListener("keydown", this.handleDelegatedKeydown, true);

    // Re-scan after Livewire swaps DOM (modals, dynamic fields).
    document.addEventListener("livewire:navigated", () => this.observeInputs());
    const hook = () =>
      window.Livewire?.hook?.("morph.updated", () => this.observeInputs());
    if (window.Livewire) hook();
    else document.addEventListener("livewire:init", hook, { once: true });
  }

  log(message) {
    if (this.debug) console.info(`[FilamentAutoTransliterate] ${message}`);
  }

  createOverlay() {
    this.overlay = document.createElement("div");
    this.overlay.className = "fat-overlay";
    document.body.appendChild(this.overlay);
  }

  observeInputs() {
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
        input.removeEventListener("focus", this.handleFocus);
        input.removeEventListener("blur", this.handleBlur);
        input.addEventListener("focus", this.handleFocus);
        input.addEventListener("blur", this.handleBlur);
      });
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
    const stored = localStorage.getItem("fat_target_lang");
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
    localStorage.setItem("fat_target_lang", code);
  }

  getTargetLang() {
    const languages = this.getLanguages();
    const stored = localStorage.getItem("fat_target_lang");
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

  // ---------------------------------------------------------------------
  // Keydown / conversion
  // ---------------------------------------------------------------------

  handleDelegatedKeydown(e) {
    const target = e.target;
    const isInput =
      target instanceof HTMLInputElement ||
      target instanceof HTMLTextAreaElement;
    if (!isInput || target.dataset.fatTranslatable !== "true") return;

    // Any keypress in the field dismisses the first-focus hint.
    if (this.hintActive && this.activeInput === target) {
      this.hideOverlay();
    }

    if (!this.isEnabled || this.isApplying) return;

    if (e.key === " " || e.code === "Space") {
      const cursor = target.selectionStart;
      const before = target.value.substring(0, cursor);
      const match = before.match(/(\S+)$/);
      if (!match) return;

      const word = match[1];
      const config = this.getConfig(target);
      const targetLang = this.resolveTargetLang(config);

      // Trigger (b): just before the next conversion is applied in this field,
      // check whether the previous applied word was hand-corrected.
      this.checkForCorrection(target, true);

      const minLength = config.minLength ?? 0;
      if (word.length < minLength) return;

      // Already in target script — leave it alone.
      if (this.getScriptPattern(targetLang).test(word)) return;

      this.startLoading(target);
      this.translateAndApply(target, word, config, targetLang);
    }
  }

  async translateAndApply(input, word, config, targetLang) {
    const cacheKey = `${config.mode}:${targetLang}:${word}`;

    // A response (cache hit or network) for this exact original word means any
    // pending correction record for the same original is now stale.
    const pendingCorrection = this.correctionRecords.get(input);
    if (pendingCorrection && pendingCorrection.original === word) {
      this.correctionRecords.delete(input);
    }

    if (this.wordCache.has(cacheKey)) {
      this.stopLoading(input);
      this.applyInline(input, word, this.wordCache.get(cacheKey), {
        targetLang,
        mode: config.mode,
      });
      return;
    }

    if (this.negativeCache.has(cacheKey)) {
      this.stopLoading(input);
      return;
    }

    if (this.isCoolingDown()) {
      this.stopLoading(input);
      return;
    }

    try {
      const result = await this.fetchTranslationDeduped(
        cacheKey,
        word,
        config,
        targetLang,
      );

      // Loading is finished the moment we have a result — always stop the
      // spinner first so no branch can leave it spinning.
      this.stopLoading(input);

      // If the user toggled the feature off while this request was in flight,
      // don't mutate the field or surface any message — honour the toggle.
      if (!this.isEnabled) return;

      if (result.ok) {
        this.wordCache.set(cacheKey, result.translated);
        this.applyInline(input, word, result.translated, {
          targetLang,
          mode: config.mode,
        });
        return;
      }

      if (result.reason === "rateLimited") {
        this.showCooldownMessageOnce(input);
      } else if (result.reason === "error") {
        this.log(`request failed (status=${result.status ?? "?"})`);
        this.showMessage(input, "Translation unavailable.");
      }
      // reason === "miss": already recorded in the negative cache; leave the
      // typed word untouched, no noise.
    } catch (error) {
      this.log(`request exception (${error.message})`);
      this.stopLoading(input);
      // Same toggle-off guard for the failure path.
      if (this.isEnabled) {
        this.showMessage(input, "Translation unavailable.");
      }
    }
  }

  // Shares one in-flight promise across concurrent requests for the same
  // `mode:lang:word` key (doc 04 client-side de-duplication).
  fetchTranslationDeduped(cacheKey, word, config, targetLang) {
    const existing = this.pendingRequests.get(cacheKey);
    if (existing) return existing;

    const promise = this.performFetch(word, config, targetLang, cacheKey).finally(
      () => this.pendingRequests.delete(cacheKey),
    );
    this.pendingRequests.set(cacheKey, promise);
    return promise;
  }

  async performFetch(word, config, targetLang, cacheKey) {
    const response = await fetch(config.endpoint, {
      method: "POST",
      headers: this.requestHeaders(),
      body: JSON.stringify({
        text: word,
        target_lang: targetLang,
        mode: config.mode,
      }),
    });

    if (!response.ok) {
      if (response.status === 429) {
        this.beginCooldown();
        return { ok: false, reason: "rateLimited", status: 429 };
      }
      return { ok: false, reason: "error", status: response.status };
    }

    const data = await response.json();
    if (data.success && data.translated) {
      return { ok: true, translated: data.translated };
    }

    this.rememberNegative(cacheKey);
    return { ok: false, reason: "miss" };
  }

  requestHeaders() {
    return {
      "Content-Type": "application/json",
      "X-CSRF-TOKEN":
        document.querySelector('meta[name="csrf-token"]')?.content || "",
      Accept: "application/json",
    };
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

  beginCooldown() {
    this.cooldownUntil = Date.now() + 15000;
    this.cooldownMessageShown = false;
  }

  showCooldownMessageOnce(input) {
    if (this.cooldownMessageShown) return;
    this.cooldownMessageShown = true;
    this.showMessage(input, "Too many translations — pausing for a moment.");
  }

  applyInline(input, originalWord, translatedWord, meta = {}) {
    const cursor = input.selectionStart;
    const before = input.value.substring(0, cursor);

    // Find the last WHOLE-TOKEN occurrence of the word (bounded by whitespace or
    // the string edges). This avoids replacing the word where it appears as a
    // substring of another word — which a slow/overlapping request could
    // otherwise do after the surrounding text has shifted.
    const lastIndex = this.lastWholeWordIndex(before, originalWord);
    if (lastIndex === -1) return;

    const prefix = before.substring(0, lastIndex);
    const suffix = before.substring(lastIndex + originalWord.length);
    const rest = input.value.substring(cursor);

    this.isApplying = true;
    input.value = prefix + translatedWord + suffix + rest;
    const newCursor = prefix.length + translatedWord.length + suffix.length;
    input.setSelectionRange(newCursor, newCursor);
    input.dispatchEvent(new Event("input", { bubbles: true }));
    this.isApplying = false;

    // Snapshot for the correction detector (doc 02) — latest per field only,
    // and only kept when learning is enabled (no point tracking corrections
    // that will never be persisted).
    if (this.learnEnabled() && meta.targetLang) {
      this.correctionRecords.set(input, {
        original: originalWord,
        applied: translatedWord,
        targetLang: meta.targetLang,
        mode: meta.mode,
        valueAfterApply: input.value,
      });
    } else {
      this.correctionRecords.delete(input);
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
    if (oldToken !== record.applied) return;
    if (!newToken || newToken === oldToken) return;
    if (!this.getScriptPattern(record.targetLang).test(newToken)) return;

    this.acceptCorrection(input, record, newToken);
  }

  tokenize(value) {
    return value.split(/\s+/).filter(Boolean);
  }

  acceptCorrection(input, record, correctedWord) {
    this.log(`learned correction: ${record.original} -> ${correctedWord}`);

    const cacheKey = `${record.mode}:${record.targetLang}:${record.original}`;
    this.wordCache.set(cacheKey, correctedWord);
    this.correctionRecords.delete(input);

    const config = this.getConfig(input);
    if (!config.learnEndpoint) return;

    // Fire-and-forget: never surfaces UI, failures are debug-logged only.
    fetch(config.learnEndpoint, {
      method: "POST",
      headers: this.requestHeaders(),
      body: JSON.stringify({
        original: record.original,
        corrected: correctedWord,
        target_lang: record.targetLang,
        mode: record.mode,
      }),
    }).catch((error) => this.log(`learn request failed (${error.message})`));
  }

  // ---------------------------------------------------------------------
  // Spinners / overlay
  // ---------------------------------------------------------------------

  // Begin the loading indicator for a field. Prefers a compact spinner inside
  // the field's trailing edge; falls back to the below-field box for hosts whose
  // inputs aren't wrapped in Filament's `.fi-input-wrp`. Reference-counted per
  // host so overlapping requests on the same field don't stack or prematurely
  // clear the spinner.
  startLoading(input) {
    const host = input.closest(".fi-input-wrp");
    if (!host) {
      this.showLoading(input);
      return;
    }

    const existing = this.spinners.get(host);
    if (existing) {
      existing.count += 1;
      return;
    }

    host.classList.add("fat-loading-host");
    const el = document.createElement("span");
    el.className = "fat-field-spinner";
    el.innerHTML =
      '<svg class="fat-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="fat-spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
    host.appendChild(el);

    this.spinners.set(host, { el, count: 1 });
  }

  // End the loading indicator for the field that owns `input`. The spinner is
  // only removed once every in-flight request for that field has finished.
  stopLoading(input) {
    const host = input.closest(".fi-input-wrp");
    const entry = host && this.spinners.get(host);
    if (entry) {
      entry.count -= 1;
      if (entry.count <= 0) {
        entry.el.remove();
        host.classList.remove("fat-loading-host");
        this.spinners.delete(host);
      }
    }

    // Only hide the below-field box if it is showing the loading state (fallback
    // path) — never clobber an error message (which manages its own dismiss).
    if (this.overlay.classList.contains("is-loading")) {
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

  showLoading(input) {
    this.activeInput = input;
    this.positionOverlay(input);
    this.overlay.classList.add("is-loading");
    this.overlay.classList.remove("is-error");
    this.hintActive = false;
    this.overlay.innerHTML =
      '<div class="fat-loading"><svg class="fat-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="fat-spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span>Translating...</span></div>';
    this.overlay.style.display = "block";
  }

  // Brief, auto-dismissing notice. Text is fixed/computed (never user input) and
  // set via textContent, so no escaping is needed. Also used for the first-focus
  // language hint (doc 03) — `dir="auto"` on the message box so an embedded
  // target-script native name (e.g. "اردو") renders correctly either way.
  showMessage(input, message) {
    this.activeInput = input;
    this.positionOverlay(input);
    this.overlay.classList.remove("is-loading");
    this.overlay.classList.add("is-error");
    this.overlay.textContent = "";

    const box = document.createElement("div");
    box.className = "fat-message";
    box.setAttribute("dir", "auto");
    const span = document.createElement("span");
    span.textContent = message;
    box.appendChild(span);
    this.overlay.appendChild(box);

    this.overlay.style.display = "block";
    setTimeout(() => this.hideOverlay(), 2500);
  }

  // First-focus-per-session hint, suppressed after the first showing via
  // sessionStorage. Reuses showMessage() so it looks and behaves like any other
  // overlay notice; `hintActive` lets a subsequent keypress dismiss it early.
  maybeShowFocusHint(input, targetLang) {
    if (sessionStorage.getItem("fat_hint_shown") === "true") return;
    sessionStorage.setItem("fat_hint_shown", "true");

    const native = this.getLanguages()[targetLang]?.native || targetLang;
    this.hintActive = true;
    this.showMessage(
      input,
      `Transliterating to ${native} — press space after a word`,
    );
  }

  hideOverlay() {
    this.overlay.style.display = "none";
    this.overlay.classList.remove("is-error", "is-loading");
    this.activeInput = null;
    this.hintActive = false;
  }

  positionOverlay(input) {
    const rect = input.getBoundingClientRect();
    const scrollY = window.scrollY || document.documentElement.scrollTop;
    this.overlay.style.top = `${rect.bottom + scrollY + 8}px`;
    this.overlay.style.left = `${rect.left}px`;
    this.overlay.style.width = `${Math.max(rect.width, 300)}px`;
  }

  handleGlobalKeydown(e) {
    if (e.key === "Escape" && this.overlay.style.display !== "none") {
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
      this.maybeShowFocusHint(e.target, this.resolveTargetLang(config));
    }
  }

  handleBlur(e) {
    const target = e.target;
    // Trigger (a): check for a hand-fixed word as the field loses focus.
    this.checkForCorrection(target, false);

    setTimeout(() => {
      if (this.activeInput === target) this.hideOverlay();
    }, 200);
  }

  getConfig(input) {
    try {
      return JSON.parse(input.dataset.fatConfig || "{}");
    } catch {
      return {
        endpoint: "/filament-auto-transliterate/translate",
        targetLang: "ur",
        mode: "transliterate",
      };
    }
  }

  toggleEnabled(enabled) {
    this.isEnabled = enabled;
    localStorage.setItem("fat_enabled", enabled ? "true" : "false");
    if (!enabled) {
      this.hideOverlay();
      this.removeAllSpinners();
    }
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => {
    window.FilamentAutoTransliterate = new FilamentAutoTransliterate();
  });
} else {
  window.FilamentAutoTransliterate = new FilamentAutoTransliterate();
}

export default FilamentAutoTransliterate;
