<div x-data="{
        enabled: window.FilamentAutoTransliterate?.isEnabled ?? false,
        switcherAllowed: @js($languageSwitcher ?? true),
        languages: window.fatConfig?.languages ?? {},
        target: window.FilamentAutoTransliterate?.getTargetLang() ?? window.fatConfig?.defaultTarget ?? 'ur',
        open: false,
        updateState(state) {
            this.enabled = state;
            window.FilamentAutoTransliterate?.toggleEnabled(state);
        },
        select(code) {
            this.target = code;
            window.FilamentAutoTransliterate?.setTargetLang(code);
            this.open = false;
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
    x-init="$nextTick(() => {
        if (window.FilamentAutoTransliterate) {
            enabled = window.FilamentAutoTransliterate.isEnabled;
            target = window.FilamentAutoTransliterate.getTargetLang() ?? target;
        }
    })"
    class="flex items-center gap-1">
    <button type="button" x-on:click="updateState(!enabled)" x-tooltip="{
            content: enabled ? 'Disable inline translation' : 'Enable inline translation',
            theme: $store.theme,
        }"
        class="relative flex items-center justify-center rounded-lg p-2 outline-none hover:bg-gray-100 focus:ring-2 focus:ring-primary-500 dark:hover:bg-white/5"
        :class="{
            'text-primary-600 dark:text-primary-400 ring-2 ring-primary-500': enabled,
            'text-gray-500 dark:text-gray-400': !enabled
        }">
        <span class="sr-only" x-text="toggleLabel">Toggle inline translation</span>

        <svg x-show="enabled" class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M15 9C15 9 13.5 10 12 10C10.5 10 9 9 9 9" stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" />
            <path d="M12 10V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="12" cy="15" r="1" fill="currentColor" />
        </svg>

        <svg x-show="!enabled" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S12 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S12 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m-15.686 0A8.959 8.959 0 013 12c0-.778.099-1.533.284-2.253m0 0A11.959 11.959 0 0112 10.5c.705 0 1.39.133 2.037.38" />
        </svg>

        <span x-show="enabled" class="absolute -top-1 -right-1 flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-primary-500"></span>
        </span>
    </button>

    {{-- Language chip: dropdown when >1 language is configured, a static label
         when exactly 1 (no point growing a menu with a single option). Hidden
         entirely when disabled, when the plugin turned the switcher off, or
         when no languages are configured at all. --}}
    <div x-show="showChip" x-cloak class="relative">
        {{-- Multi-language: chip opens an Alpine dropdown. --}}
        <template x-if="codes.length > 1">
            <div @keydown.escape.stop.prevent="open = false" @click.outside="open = false">
                <button type="button" x-on:click="open = !open" aria-haspopup="listbox" :aria-expanded="open"
                    class="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-600 outline-none hover:bg-gray-100 focus:ring-2 focus:ring-primary-500 dark:text-gray-300 dark:hover:bg-white/5">
                    <span :lang="target" :dir="activeDir" x-text="activeNative"></span>
                    <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                            clip-rule="evenodd" />
                    </svg>
                </button>

                <ul x-show="open" x-transition role="listbox" aria-label="Target language"
                    class="fat-lang-dropdown absolute end-0 z-50 mt-1 max-h-64 min-w-[10rem] overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg focus:outline-none dark:border-gray-700 dark:bg-gray-800">
                    <template x-for="code in codes" :key="code">
                        <li role="option" :aria-selected="code === target" x-on:click="select(code)"
                            class="fat-lang-option flex cursor-pointer items-center justify-between gap-2 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5"
                            :class="{ 'font-semibold': code === target }">
                            <span>
                                <span :lang="code" :dir="languages[code]?.rtl ? 'rtl' : 'ltr'" x-text="languages[code]?.native"></span>
                                <span class="text-gray-400"> — </span>
                                <span x-text="languages[code]?.label"></span>
                            </span>
                            <svg x-show="code === target" class="h-4 w-4 flex-shrink-0 text-primary-600 dark:text-primary-400"
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
            <span class="fat-lang-static flex items-center gap-1 px-2 py-1.5 text-sm font-medium text-gray-500 dark:text-gray-400">
                <span :lang="target" :dir="activeDir" x-text="activeNative"></span>
            </span>
        </template>
    </div>
</div>
