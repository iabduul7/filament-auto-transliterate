/**
 * Filament Auto Translate — inline, as-you-type overlay.
 *
 * Watches inputs tagged with `data-fat-translatable="true"`. On space, the last
 * word is sent to the package endpoint and the returned target-script text is
 * written back inline. Toggle on/off via the header button (state persists in
 * localStorage). Framework-free so it can ship as a prebuilt asset.
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

  handleDelegatedKeydown(e) {
    const target = e.target;
    const isInput =
      target instanceof HTMLInputElement ||
      target instanceof HTMLTextAreaElement;
    if (!isInput || target.dataset.fatTranslatable !== "true") return;
    if (!this.isEnabled || this.isApplying) return;

    if (e.key === " " || e.code === "Space") {
      const cursor = target.selectionStart;
      const before = target.value.substring(0, cursor);
      const match = before.match(/(\S+)$/);
      if (!match) return;

      const word = match[1];
      // Already in target script (Arabic/Urdu block) — leave it alone.
      if (/[؀-ۿ]/.test(word)) return;

      this.startLoading(target);
      this.translateAndApply(target, word, this.getConfig(target));
    }
  }

  async translateAndApply(input, word, config) {
    const cacheKey = `${config.mode}:${config.targetLang}:${word}`;
    if (this.wordCache.has(cacheKey)) {
      this.stopLoading(input);
      this.applyInline(input, word, this.wordCache.get(cacheKey));
      return;
    }

    try {
      const response = await fetch(config.endpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-TOKEN":
            document.querySelector('meta[name="csrf-token"]')?.content || "",
          Accept: "application/json",
        },
        body: JSON.stringify({
          text: word,
          target_lang: config.targetLang,
          mode: config.mode,
        }),
      });

      // Loading is finished the moment we have a response (or an error) — always
      // stop the spinner first so no branch can leave it spinning.
      this.stopLoading(input);

      if (!response.ok) {
        this.log(`request failed (status=${response.status})`);
        this.showMessage(
          input,
          response.status === 429
            ? "Too many translations — pausing for a moment."
            : "Translation unavailable.",
        );
        return;
      }

      const data = await response.json();
      if (data.success && data.translated) {
        this.wordCache.set(cacheKey, data.translated);
        this.applyInline(input, word, data.translated);
      }
      // No conversion available: spinner already stopped; leave the typed word
      // untouched, no noise.
    } catch (error) {
      this.log(`request exception (${error.message})`);
      this.stopLoading(input);
      this.showMessage(input, "Translation unavailable.");
    }
  }

  applyInline(input, originalWord, translatedWord) {
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
    this.overlay.innerHTML =
      '<div class="fat-loading"><svg class="fat-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="fat-spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span>Translating...</span></div>';
    this.overlay.style.display = "block";
  }

  // Brief, auto-dismissing notice. Text is fixed and set via textContent, so no
  // escaping is needed.
  showMessage(input, message) {
    this.activeInput = input;
    this.positionOverlay(input);
    this.overlay.classList.remove("is-loading");
    this.overlay.classList.add("is-error");
    this.overlay.textContent = "";

    const box = document.createElement("div");
    box.className = "fat-message";
    const span = document.createElement("span");
    span.textContent = message;
    box.appendChild(span);
    this.overlay.appendChild(box);

    this.overlay.style.display = "block";
    setTimeout(() => this.hideOverlay(), 2500);
  }

  hideOverlay() {
    this.overlay.style.display = "none";
    this.overlay.classList.remove("is-error", "is-loading");
    this.activeInput = null;
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
  }

  handleBlur(e) {
    setTimeout(() => {
      if (this.activeInput === e.target) this.hideOverlay();
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
