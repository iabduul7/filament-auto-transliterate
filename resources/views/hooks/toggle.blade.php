<div
    x-data="{
        enabled: window.filamentAutoTranslate?.isEnabled ?? false,
        updateState(state) {
            this.enabled = state;
            window.filamentAutoTranslate?.toggleEnabled(state);
        }
    }"
    x-init="$nextTick(() => { if (window.filamentAutoTranslate) enabled = window.filamentAutoTranslate.isEnabled; })"
    class="flex items-center"
>
    <button
        type="button"
        x-on:click="updateState(!enabled)"
        x-tooltip="{
            content: enabled ? 'Disable inline translation' : 'Enable inline translation',
            theme: $store.theme,
        }"
        class="relative flex items-center justify-center rounded-lg p-2 outline-none hover:bg-gray-100 focus:ring-2 focus:ring-primary-500 dark:hover:bg-white/5"
        :class="{
            'text-primary-600 dark:text-primary-400': enabled,
            'text-gray-500 dark:text-gray-400': !enabled
        }"
    >
        <span class="sr-only">Toggle inline translation</span>

        <svg x-show="enabled" class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M15 9C15 9 13.5 10 12 10C10.5 10 9 9 9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M12 10V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="12" cy="15" r="1" fill="currentColor"/>
        </svg>

        <svg x-show="!enabled" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S12 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S12 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m-15.686 0A8.959 8.959 0 013 12c0-.778.099-1.533.284-2.253m0 0A11.959 11.959 0 0112 10.5c.705 0 1.39.133 2.037.38" />
        </svg>

        <span x-show="enabled" class="absolute -top-1 -right-1 flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-primary-500"></span>
        </span>
    </button>
</div>
