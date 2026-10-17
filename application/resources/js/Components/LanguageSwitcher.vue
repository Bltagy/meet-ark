<script setup>
import { useI18n } from '@/i18n';

const { locale, setLocale } = useI18n();

defineProps({
    compact: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div
        class="inline-flex items-center rounded-md border border-gray-200 bg-gray-50 p-0.5 text-xs font-medium shadow-xs select-none"
        role="group"
        aria-label="Language Switcher"
    >
        <button
            type="button"
            @click="setLocale('ar')"
            :class="[
                'rounded px-2.5 py-1 text-xs font-medium transition-colors focus:outline-none',
                locale === 'ar'
                    ? 'bg-white text-indigo-700 shadow-xs font-semibold'
                    : 'text-gray-600 hover:text-gray-900'
            ]"
            :aria-pressed="locale === 'ar'"
            lang="ar"
            dir="rtl"
        >
            العربية
        </button>
        <button
            type="button"
            @click="setLocale('en')"
            :class="[
                'rounded px-2.5 py-1 text-xs font-medium transition-colors focus:outline-none',
                locale === 'en'
                    ? 'bg-white text-indigo-700 shadow-xs font-semibold'
                    : 'text-gray-600 hover:text-gray-900'
            ]"
            :aria-pressed="locale === 'en'"
            lang="en"
            dir="ltr"
        >
            English
        </button>
    </div>
</template>
