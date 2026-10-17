<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatMeetingTime, useI18n } from '@/i18n';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    meeting: {
        type: Object,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
    jitsi: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const { locale } = useI18n();
const jitsiContainer = ref(null);
const isCopied = ref(false);
const isLoading = ref(true);
const loadError = ref(null);

let jitsiApi = null;

const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

const formatDateTime = (isoString) => {
    return formatMeetingTime(isoString, locale.value, userTimeZone);
};

const copyInvitationLink = async () => {
    const url = props.meeting.invitation_url;
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(url);
            isCopied.value = true;
            setTimeout(() => {
                isCopied.value = false;
            }, 2000);
            return;
        }
        fallbackCopy(url);
    } catch (e) {
        fallbackCopy(url);
    }
};

const fallbackCopy = (text) => {
    try {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        const successful = document.execCommand('copy');
        document.body.removeChild(textArea);
        if (successful) {
            isCopied.value = true;
            setTimeout(() => {
                isCopied.value = false;
            }, 2000);
            return;
        }
    } catch (e) {
        // execCommand failed
    }
    window.prompt(page.props.locale === 'ar' ? 'انسخ رابط الدعوة:' : 'Copy invitation link:', text);
};

const loadJitsiScript = () => {
    return new Promise((resolve, reject) => {
        if (window.JitsiMeetExternalAPI) {
            resolve();
            return;
        }

        const scriptUrl = `${props.jitsi.url}/external_api.js`;
        const existingScript = document.querySelector(`script[src="${scriptUrl}"]`);
        if (existingScript) {
            existingScript.addEventListener('load', () => resolve());
            existingScript.addEventListener('error', () => reject(new Error('Failed to load Jitsi external_api.js')));
            return;
        }

        const script = document.createElement('script');
        script.src = scriptUrl;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load Jitsi external_api.js from ${scriptUrl}`));
        document.head.appendChild(script);
    });
};

const initializeJitsi = async () => {
    try {
        isLoading.value = true;
        await loadJitsiScript();

        if (!jitsiContainer.value) return;

        // Parse host from configured Jitsi URL (e.g. "localhost:8443")
        const parsedUrl = new URL(props.jitsi.url);
        const domain = parsedUrl.host;
        const currentLang = page.props.locale || locale.value || 'ar';

        const options = {
            roomName: props.meeting.room_name,
            width: '100%',
            height: '100%',
            parentNode: jitsiContainer.value,
            jwt: props.token,
            lang: currentLang,
            userInfo: {
                displayName: page.props.auth.user.name,
                email: page.props.auth.user.email,
            },
            configOverwrite: {
                defaultLanguage: currentLang,
                prejoinConfig: {
                    enabled: true,
                },
                startWithAudioMuted: false,
                startWithVideoMuted: false,
            },
            interfaceConfigOverwrite: {},
        };

        jitsiApi = new window.JitsiMeetExternalAPI(domain, options);
        isLoading.value = false;
    } catch (err) {
        isLoading.value = false;
        loadError.value = err.message || 'Error initializing embedded Jitsi meeting.';
        console.error('Jitsi initialization error:', err);
    }
};

onMounted(() => {
    initializeJitsi();
});

onUnmounted(() => {
    // Correctly dispose of the embedded Jitsi meeting when navigating away
    if (jitsiApi) {
        jitsiApi.dispose();
        jitsiApi = null;
    }
});
</script>

<template>
    <Head :title="meeting.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2 class="text-xl font-semibold leading-tight text-gray-800">
                            {{ meeting.title }}
                        </h2>
                        <span
                            v-if="meeting.is_scheduled"
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200"
                        >
                            {{ $t('meeting.scheduledBadge') }}
                        </span>
                        <span
                            v-else
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 border border-gray-200"
                        >
                            {{ $t('meeting.instantBadge') }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span v-if="meeting.is_scheduled">
                            📅 <strong>{{ formatDateTime(meeting.starts_at) }}</strong> <span dir="ltr">({{ userTimeZone }})</span> • ⏱️ {{ meeting.duration_minutes }} {{ $t('common.mins') }}
                        </span>
                        <span v-else>
                            {{ $t('meeting.createdInfo', { date: formatDateTime(meeting.created_at) }) }}
                        </span>
                        <span>•</span>
                        <span class="font-mono text-gray-500" dir="ltr">{{ $t('common.room') }}: {{ meeting.room_name }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <button
                        type="button"
                        @click="copyInvitationLink"
                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-xs text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        <span v-if="isCopied" class="text-green-600 font-semibold">{{ $t('meeting.linkCopied') }}</span>
                        <span v-else>{{ $t('meeting.copyInvitationLink') }}</span>
                    </button>

                    <Link
                        :href="route('dashboard')"
                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs sm:text-sm font-medium rounded-md text-gray-700 bg-gray-50 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        {{ $t('meeting.dashboard') }}
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-4">
                <!-- Security & Token Status Notice -->
                <div class="bg-indigo-50 border-s-4 border-indigo-500 p-3 text-xs text-indigo-900 rounded">
                    {{ $t('meeting.tokenSecured') }}
                </div>

                <!-- Jitsi Meeting Embed Container -->
                <div class="bg-gray-900 rounded-lg shadow overflow-hidden relative min-h-[460px] sm:min-h-[550px] h-[calc(100vh-210px)] sm:h-[calc(100vh-220px)]">
                    <div
                        v-if="isLoading"
                        class="absolute inset-0 flex flex-col items-center justify-center text-white bg-gray-900 bg-opacity-75 z-10"
                    >
                        <div class="animate-spin rounded-full h-10 w-10 border-4 border-indigo-500 border-t-transparent mb-3"></div>
                        <p class="text-sm px-4 text-center" dir="auto">{{ $t('meeting.connecting', { url: jitsi.url }) }}</p>
                    </div>

                    <div
                        v-if="loadError"
                        class="absolute inset-0 flex flex-col items-center justify-center text-red-400 p-6 text-center z-10"
                    >
                        <p class="font-semibold text-lg mb-2">{{ $t('meeting.loadErrorTitle') }}</p>
                        <p class="text-sm text-gray-300" dir="ltr">{{ loadError }}</p>
                        <p class="text-xs text-gray-400 mt-2" dir="auto">{{ $t('meeting.loadErrorHint', { url: jitsi.url }) }}</p>
                    </div>

                    <!-- DOM Mount for Jitsi IFrame -->
                    <div ref="jitsiContainer" class="w-full h-full"></div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
