import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import en from './locales/en.json';
import ar from './locales/ar.json';

const dictionaries = {
    en,
    ar,
};

// Detect initial locale from document lang or cookie or default to 'ar'
const getInitialLocale = () => {
    if (typeof document !== 'undefined') {
        const docLang = document.documentElement.lang;
        if (docLang && (docLang === 'ar' || docLang === 'en')) {
            return docLang;
        }

        const match = document.cookie.match(/(?:^|;\s*)locale=([^;]+)/);
        if (match && (match[1] === 'ar' || match[1] === 'en')) {
            return match[1];
        }
    }
    return 'ar';
};

const activeLocale = ref(getInitialLocale());

export const currentLocale = computed(() => activeLocale.value);
export const currentDir = computed(() => (activeLocale.value === 'ar' ? 'rtl' : 'ltr'));

export function syncLocale(locale) {
    if (locale && (locale === 'ar' || locale === 'en') && activeLocale.value !== locale) {
        activeLocale.value = locale;
    }
    if (typeof document !== 'undefined') {
        document.documentElement.lang = activeLocale.value;
        document.documentElement.dir = activeLocale.value === 'ar' ? 'rtl' : 'ltr';
    }
}

export function t(key, params = {}) {
    const locale = activeLocale.value;
    const parts = key.split('.');

    let translation = parts.reduce((obj, part) => obj && obj[part], dictionaries[locale]);

    // Fallback to English if translation is missing in active locale
    if (translation === undefined && locale !== 'en') {
        translation = parts.reduce((obj, part) => obj && obj[part], dictionaries.en);
    }

    if (typeof translation !== 'string') {
        return key;
    }

    // Replace {placeholder} parameters
    return translation.replace(/\{(\w+)\}/g, (_, placeholder) => {
        return params[placeholder] !== undefined ? params[placeholder] : `{${placeholder}}`;
    });
}

export function setLocale(newLocale) {
    if (newLocale !== 'ar' && newLocale !== 'en') return;

    activeLocale.value = newLocale;

    if (typeof document !== 'undefined') {
        document.documentElement.lang = newLocale;
        document.documentElement.dir = newLocale === 'ar' ? 'rtl' : 'ltr';
        document.cookie = `locale=${newLocale};path=/;max-age=31536000;SameSite=Lax`;
    }

    try {
        router.post(
            window.route ? window.route('locale.update') : '/locale',
            { locale: newLocale },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const serverLocale = page.props.locale || newLocale;
                    syncLocale(serverLocale);
                },
            }
        );
    } catch (e) {
        // Fallback for direct page reload if route() is unavailable
        window.location.reload();
    }
}

export function formatMeetingTime(isoString, locale = activeLocale.value, timeZone = 'UTC') {
    if (!isoString) return '';
    const date = new Date(isoString);
    if (isNaN(date.getTime())) return isoString;

    try {
        const intlLocale = locale === 'ar' ? 'ar-EG' : 'en-US';
        return new Intl.DateTimeFormat(intlLocale, {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: timeZone || 'UTC',
        }).format(date);
    } catch (e) {
        return date.toLocaleString();
    }
}

export function useI18n() {
    return {
        locale: currentLocale,
        dir: currentDir,
        t,
        setLocale,
        formatMeetingTime,
    };
}
