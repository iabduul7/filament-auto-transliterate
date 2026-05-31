/**
 * Filament Auto Translate — inline, as-you-type overlay.
 *
 * Watches inputs tagged with `data-fat-translatable="true"`. On space, the last
 * word is sent to the package endpoint and the returned target-script text is
 * written back inline. Toggle on/off via the header button (state persists in
 * localStorage). Framework-free so it can ship as a prebuilt asset.
 */
class FilamentAutoTranslate {
  constructor() {
    this.overlay = null;
    this.activeInput = null;
    this.wordCache = new Map();
    this.isEnabled = localStorage.getItem('fat_enabled') === 'true';
    this.isApplying = false;
    this.debug = false;

    this.handleFocus = this.handleFocus.bind(this);
    this.handleBlur = this.handleBlur.bind(this);
    this.handleDelegatedKeydown = this.handleDelegatedKeydown.bind(this);
    this.handleGlobalKeydown = this.handleGlobalKeydown.bind(this);

    this.init();
  }

  init() {
    this.createOverlay();
    this.observeInputs();

    document.addEventListener('keydown', this.handleGlobalKeydown);
    document.addEventListener('keydown', this.handleDelegatedKeydown, true);

    // Re-scan after Livewire swaps DOM (modals, dynamic fields).
    document.addEventListener('livewire:navigated', () => this.observeInputs());
    const hook = () => window.Livewire?.hook?.('morph.updated', () => this.observeInputs());
    if (window.Livewire) hook();
    else document.addEventListener('livewire:init', hook, { once: true });
  }

  log(message) {
    if (this.debug) console.info(`[FilamentAutoTranslate] ${message}`);
  }

  createOverlay() {
    this.overlay = document.createElement('div');
    this.overlay.className = 'fat-overlay';
    document.body.appendChild(this.overlay);
  }

  observeInputs() {
    document.querySelectorAll('[data-fat-translatable="true"]').forEach((input) => {
      input.removeEventListener('focus', this.handleFocus);
      input.removeEventListener('blur', this.handleBlur);
      input.addEventListener('focus', this.handleFocus);
      input.addEventListener('blur', this.handleBlur);
    });
  }

  handleDelegatedKeydown(e) {
    const target = e.target;
    const isInput = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement;
    if (!isInput || target.dataset.fatTranslatable !== 'true') return;
    if (!this.isEnabled || this.isApplying) return;

    if (e.key === ' ' || e.code === 'Space') {
      const cursor = target.selectionStart;
      const before = target.value.substring(0, cursor);
      const match = before.match(/(\S+)$/);
      if (!match) return;

      const word = match[1];
      // Already in target script (Arabic/Urdu block) — leave it alone.
      if (/[؀-ۿ]/.test(word)) return;

      this.showLoading(target);
      this.translateAndApply(target, word, this.getConfig(target));
    }
  }

  async translateAndApply(input, word, config) {
    const cacheKey = `${config.mode}:${config.targetLang}:${word}`;
    if (this.wordCache.has(cacheKey)) {
      this.applyInline(input, word, this.wordCache.get(cacheKey));
      this.hideOverlay();
      return;
    }

    try {
      const response = await fetch(config.endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
          Accept: 'application/json',
        },
        body: JSON.stringify({ text: word, target_lang: config.targetLang, mode: config.mode }),
      });

      if (!response.ok) {
        this.log(`request failed (status=${response.status})`);
        this.showMessage(
          input,
          response.status === 429
            ? 'Too many translations — pausing for a moment.'
            : 'Translation unavailable.'
        );
        return;
      }

      const data = await response.json();
      if (data.success && data.translated) {
        this.wordCache.set(cacheKey, data.translated);
        this.applyInline(input, word, data.translated);
        this.hideOverlay();
      } else {
        // No conversion available: leave the typed word untouched, no noise.
        this.hideOverlay();
      }
    } catch (error) {
      this.log(`request exception (${error.message})`);
      this.showMessage(input, 'Translation unavailable.');
    }
  }

  applyInline(input, originalWord, translatedWord) {
    const cursor = input.selectionStart;
    const before = input.value.substring(0, cursor);
    const lastIndex = before.lastIndexOf(originalWord);
    if (lastIndex === -1) return;

    const prefix = before.substring(0, lastIndex);
    const suffix = before.substring(lastIndex + originalWord.length);
    const rest = input.value.substring(cursor);

    this.isApplying = true;
    input.value = prefix + translatedWord + suffix + rest;
    const newCursor = prefix.length + translatedWord.length + suffix.length;
    input.setSelectionRange(newCursor, newCursor);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    this.isApplying = false;
  }

  showLoading(input) {
    this.activeInput = input;
    this.positionOverlay(input);
    this.overlay.classList.add('is-loading');
    this.overlay.classList.remove('is-error');
    this.overlay.innerHTML =
      '<div class="fat-loading"><svg class="fat-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="fat-spin-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg><span>Translating...</span></div>';
    this.overlay.style.display = 'block';
  }

  // Brief, auto-dismissing notice. Text is fixed and set via textContent, so no
  // escaping is needed.
  showMessage(input, message) {
    this.activeInput = input;
    this.positionOverlay(input);
    this.overlay.classList.remove('is-loading');
    this.overlay.classList.add('is-error');
    this.overlay.textContent = '';

    const box = document.createElement('div');
    box.className = 'fat-message';
    const span = document.createElement('span');
    span.textContent = message;
    box.appendChild(span);
    this.overlay.appendChild(box);

    this.overlay.style.display = 'block';
    setTimeout(() => this.hideOverlay(), 2500);
  }

  hideOverlay() {
    this.overlay.style.display = 'none';
    this.overlay.classList.remove('is-error', 'is-loading');
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
    if (e.key === 'Escape' && this.overlay.style.display !== 'none') {
      e.preventDefault();
      this.hideOverlay();
    }
  }

  handleFocus(e) {
    if (this.activeInput === e.target && this.overlay.style.display === 'block') {
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
      return JSON.parse(input.dataset.fatConfig || '{}');
    } catch {
      return { endpoint: '/filament-auto-translate/translate', targetLang: 'ur', mode: 'transliterate' };
    }
  }

  toggleEnabled(enabled) {
    this.isEnabled = enabled;
    localStorage.setItem('fat_enabled', enabled ? 'true' : 'false');
    if (!enabled) this.hideOverlay();
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    window.filamentAutoTranslate = new FilamentAutoTranslate();
  });
} else {
  window.filamentAutoTranslate = new FilamentAutoTranslate();
}

export default FilamentAutoTranslate;
