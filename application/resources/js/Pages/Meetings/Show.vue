<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
const jitsiContainer = ref(null);
const isCopied = ref(false);
const isLoading = ref(true);
const loadError = ref(null);

let jitsiApi = null;

const copyInvitationLink = async () => {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(props.meeting.invitation_url);
        } else {
            const textArea = document.createElement('textarea');
            textArea.value = props.meeting.invitation_url;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            document.execCommand('copy');
            textArea.remove();
        }
        isCopied.value = true;
        setTimeout(() => {
            isCopied.value = false;
        }, 2000);
    } catch (e) {
        console.error('Failed to copy invitation link:', e);
    }
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

        const options = {
            roomName: props.meeting.room_name,
            width: '100%',
            height: '100%',
            parentNode: jitsiContainer.value,
            jwt: props.token,
            userInfo: {
                displayName: page.props.auth.user.name,
                email: page.props.auth.user.email,
            },
            configOverwrite: {
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
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        {{ meeting.title }}
                    </h2>
                    <p class="text-xs text-gray-500 font-mono mt-0.5">
                        Room: {{ meeting.room_name }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="copyInvitationLink"
                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        <span v-if="isCopied" class="text-green-600 font-semibold">Link Copied!</span>
                        <span v-else>Copy Invitation Link</span>
                    </button>

                    <Link
                        :href="route('dashboard')"
                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-gray-50 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                        Dashboard
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">
                <!-- Security & Token Status Notice -->
                <div class="bg-indigo-50 border-l-4 border-indigo-500 p-3 text-xs text-indigo-900 rounded">
                    <strong>Token-Secured Access:</strong> This meeting session is authenticated using a room-scoped JWT issued by Meet-Ark. Anonymous direct access is blocked by the media server.
                </div>

                <!-- Jitsi Meeting Embed Container -->
                <div class="bg-gray-900 rounded-lg shadow overflow-hidden relative" style="height: calc(100vh - 220px); min-height: 550px;">
                    <div
                        v-if="isLoading"
                        class="absolute inset-0 flex flex-col items-center justify-center text-white bg-gray-900 bg-opacity-75 z-10"
                    >
                        <div class="animate-spin rounded-full h-10 w-10 border-4 border-indigo-500 border-t-transparent mb-3"></div>
                        <p class="text-sm">Connecting to Jitsi Meet ({{ jitsi.url }})...</p>
                    </div>

                    <div
                        v-if="loadError"
                        class="absolute inset-0 flex flex-col items-center justify-center text-red-400 p-6 text-center z-10"
                    >
                        <p class="font-semibold text-lg mb-2">Failed to load meeting room</p>
                        <p class="text-sm text-gray-300">{{ loadError }}</p>
                        <p class="text-xs text-gray-400 mt-2">Ensure Jitsi services are running on {{ jitsi.url }}</p>
                    </div>

                    <!-- DOM Mount for Jitsi IFrame -->
                    <div ref="jitsiContainer" class="w-full h-full"></div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
