<div x-data="{
        enabled: window.FilamentAutoTransliterate?.isEnabled ?? false,
        switcherAllowed: @js($languageSwitcher ?? true),
        languages: window.fatConfig?.languages ?? {},
        target: window.FilamentAutoTransliterate?.getTargetLang() ?? window.fatConfig?.defaultTarget ?? 'ar',
        open: false,
        updateState(state) {
            this.enabled = state;
            window.FilamentAutoTransliterate?.toggleEnabled(state);
        },
        sync() {
            const instance = window.FilamentAutoTransliterate;
            if (!instance) return;
            this.enabled = instance.isEnabled;
            this.languages = window.fatConfig?.languages ?? this.languages;
            this.target = instance.getTargetLang() ?? this.target;
        },
        select(code) {
            this.target = code;
            window.FilamentAutoTransliterate?.setTargetLang(code);
            this.closeMenu(true);
        },
        openMenu() {
            this.open = true;
            this.$nextTick(() => {
                const options = this.options();
                const selected = options.find((o) => o.getAttribute('aria-selected') === 'true');
                (selected ?? options[0])?.focus();
            });
        },
        closeMenu(returnFocus = false) {
            this.open = false;
            if (returnFocus) this.$nextTick(() => this.$refs.chip?.focus());
        },
        options() {
            return Array.from(this.$refs.list?.querySelectorAll('[role=option]') ?? []);
        },
        move(delta, absolute = false) {
            const options = this.options();
            if (!options.length) return;
            const current = options.indexOf(document.activeElement);
            const next = absolute
                ? (delta < 0 ? 0 : options.length - 1)
                : Math.max(0, Math.min(options.length - 1, (current < 0 ? 0 : current) + delta));
            options[next].focus();
        },
        get codes() {
            return Object.keys(this.languages);
        },
        get activeLanguage() {
            return this.languages[this.target] ?? null;
        },
        get activeNative() {
            return this.activeLanguage?.native ?? this.target;
        },
        get activeDir() {
            return this.activeLanguage?.rtl ? 'rtl' : 'ltr';
        },
        get toggleLabel() {
            return this.enabled
                ? ('Inline transliteration on, ' + (this.activeLanguage?.label ?? this.target))
                : 'Inline transliteration off';
        },
        get showChip() {
            return this.enabled && this.switcherAllowed && this.codes.length >= 1;
        },
    }"
    x-init="
        sync();
        window.addEventListener('fat:ready', () => sync());
        window.addEventListener('fat:toggled', (e) => enabled = e.detail.enabled);
        window.addEventListener('fat:language-changed', (e) => target = e.detail.code);
        $nextTick(() => sync());
    "
    class="fat-header-switcher">
    <button type="button" x-on:click="updateState(!enabled)" :aria-pressed="enabled" x-tooltip="{
            content: enabled ? 'Disable inline transliteration' : 'Enable inline transliteration',
            theme: $store.theme,
        }"
        class="fat-toggle-btn"
        :class="{ 'is-enabled': enabled }">
        <span class="fat-sr-only" x-text="toggleLabel">Toggle inline transliteration</span>

        <svg x-show="enabled" x-cloak class="fat-toggle-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M15 9C15 9 13.5 10 12 10C10.5 10 9 9 9 9" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" />
            <path d="M12 10V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="12" cy="15" r="1" fill="currentColor" />
        </svg>

        <svg x-show="!enabled" x-cloak class="fat-toggle-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S12 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S12 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m-15.686 0A8.959 8.959 0 013 12c0-.778.099-1.533.284-2.253m0 0A11.959 11.959 0 0112 10.5c.705 0 1.39.133 2.037.38" />
        </svg>

        <span x-show="enabled" x-cloak class="fat-toggle-dot">
            <span class="fat-toggle-dot-ping"></span>
            <span class="fat-toggle-dot-core"></span>
        </span>
    </button>

    {{-- Language chip: dropdown when >1 language is configured, a static label
         when exactly 1 (no point growing a menu with a single option). Hidden
         entirely when disabled, when the plugin turned the switcher off, or
         when no languages are configured at all. --}}
    <div x-show="showChip" x-cloak class="fat-chip-wrap">
        {{-- Multi-language: chip opens an Alpine dropdown. --}}
        <template x-if="codes.length > 1">
            <div @keydown.escape.stop.prevent="closeMenu(true)" @click.outside="closeMenu()">
                <button type="button" x-ref="chip" x-on:click="open ? closeMenu() : openMenu()"
                    @keydown.arrow-down.prevent="openMenu()" aria-haspopup="listbox" :aria-expanded="open"
                    class="fat-lang-chip">
                    <span :lang="target" :dir="activeDir" x-text="activeNative"></span>
                    <svg class="fat-chip-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                            clip-rule="evenodd" />
                    </svg>
                </button>

                <ul x-show="open" x-cloak x-transition x-ref="list" role="listbox" aria-label="Target language"
                    @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                    @keydown.home.prevent="move(-1, true)" @keydown.end.prevent="move(1, true)"
                    @keydown.tab="closeMenu()"
                    class="fat-lang-dropdown">
                    <template x-for="code in codes" :key="code">
                        <li role="option" :aria-selected="code === target" x-on:click="select(code)"
                            :tabindex="code === target ? 0 : -1" @keydown.enter.prevent="select(code)"
                            @keydown.space.prevent="select(code)"
                            class="fat-lang-option"
                            :class="{ 'is-selected': code === target }">
                            <span class="fat-lang-option-label">
                                <span :lang="code" :dir="languages[code]?.rtl ? 'rtl' : 'ltr'" x-text="languages[code]?.native"></span>
                                <span class="fat-lang-option-sep"> — </span>
                                <span x-text="languages[code]?.label"></span>
                            </span>
                            <svg x-show="code === target" class="fat-lang-option-check"
                                viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                                    clip-rule="evenodd" />
                            </svg>
                        </li>
                    </template>
                </ul>
            </div>
        </template>

        {{-- Single language: static label, no dropdown. --}}
        <template x-if="codes.length === 1">
            <span class="fat-lang-static">
                <span :lang="target" :dir="activeDir" x-text="activeNative"></span>
            </span>
        </template>
    </div>
</div>
