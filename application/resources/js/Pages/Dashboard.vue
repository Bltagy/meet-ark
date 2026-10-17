<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatMeetingTime, useI18n } from '@/i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    meetings: {
        type: Array,
        default: () => [],
    },
    upcomingMeetings: {
        type: Array,
        default: () => [],
    },
    recentMeetings: {
        type: Array,
        default: () => [],
    },
});

const { locale, t } = useI18n();

// Detect viewer's IANA timezone
const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

// Mode switcher: 'instant' | 'scheduled'
const createMode = ref('instant');

// Get default min datetime for input (now + 2 minutes in local YYYY-MM-DDTHH:mm format)
const toLocalDatetimeString = (date) => {
    const d = new Date(date);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const minDateTime = computed(() => {
    return toLocalDatetimeString(Date.now() + 60000);
});

// Creation Form
const createForm = useForm({
    type: 'instant',
    title: '',
    starts_at: '',
    duration_minutes: 60,
    timezone: userTimeZone,
});

const switchMode = (mode) => {
    createMode.value = mode;
    createForm.type = mode;
    if (mode === 'scheduled' && !createForm.starts_at) {
        // Default to next hour
        const nextHour = new Date(Date.now() + 3600000);
        nextHour.setMinutes(0, 0, 0);
        createForm.starts_at = toLocalDatetimeString(nextHour);
        createForm.duration_minutes = 60;
    }
};

const submitCreate = () => {
    createForm.timezone = userTimeZone;
    createForm.post(route('meetings.store'), {
        onSuccess: () => {
            createForm.reset();
            createMode.value = 'instant';
            createForm.type = 'instant';
        },
    });
};

// Clipboard copy with resilient fallback
const copiedId = ref(null);

const copyLink = async (meeting) => {
    const url = meeting.url;
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(url);
            copiedId.value = meeting.id;
            setTimeout(() => {
                copiedId.value = null;
            }, 2000);
            return;
        }
        fallbackCopy(url, meeting.id);
    } catch (err) {
        fallbackCopy(url, meeting.id);
    }
};

const fallbackCopy = (text, id) => {
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
            copiedId.value = id;
            setTimeout(() => {
                copiedId.value = null;
            }, 2000);
            return;
        }
    } catch (e) {
        // execCommand failed
    }
    window.prompt(locale.value === 'ar' ? 'انسخ رابط الدعوة:' : 'Copy invitation link:', text);
};

// Delete meeting
const deleteMeeting = (meeting) => {
    if (confirm(t('dashboard.deleteConfirm', { title: meeting.title }))) {
        useForm({}).delete(route('meetings.destroy', meeting.id));
    }
};

// Edit Modal State & Form
const editingMeeting = ref(null);
const isEditModalOpen = ref(false);

const editForm = useForm({
    title: '',
    is_scheduled: false,
    starts_at: '',
    duration_minutes: 60,
    timezone: userTimeZone,
});

const openEditModal = (meeting) => {
    editingMeeting.value = meeting;
    editForm.title = meeting.title;
    editForm.is_scheduled = !!meeting.starts_at;
    editForm.starts_at = meeting.starts_at ? toLocalDatetimeString(new Date(meeting.starts_at)) : '';
    editForm.duration_minutes = meeting.duration_minutes || 60;
    editForm.timezone = userTimeZone;
    editForm.clearErrors();
    isEditModalOpen.value = true;
};

const closeEditModal = () => {
    isEditModalOpen.value = false;
    editingMeeting.value = null;
    editForm.reset();
};

const submitEdit = () => {
    if (!editingMeeting.value) return;

    editForm.timezone = userTimeZone;
    const payload = {
        title: editForm.title,
        starts_at: editForm.is_scheduled ? editForm.starts_at : null,
        duration_minutes: editForm.is_scheduled ? editForm.duration_minutes : null,
        timezone: userTimeZone,
    };

    editForm.transform(() => payload).patch(route('meetings.update', editingMeeting.value.id), {
        onSuccess: () => closeEditModal(),
    });
};

// Date / Time display formatter
const formatMeetingDisplay = (isoString) => {
    return formatMeetingTime(isoString, locale.value, userTimeZone);
};
</script>

<template>
    <Head :title="$t('dashboard.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ $t('dashboard.title') }}
            </h2>
        </template>

        <div class="py-6 sm:py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:space-y-8 px-4 sm:px-6 lg:px-8">
                <!-- Create & Schedule Meeting Card -->
                <div class="bg-white p-4 sm:p-6 shadow-xs rounded-lg">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 mb-4 border-b border-gray-100 gap-4">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">
                                {{ $t('dashboard.createTitle') }}
                            </h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $t('dashboard.createDesc') }}
                            </p>
                        </div>

                        <!-- Mode Switcher Buttons -->
                        <div class="inline-flex w-full sm:w-auto rounded-md shadow-xs p-1 bg-gray-100">
                            <button
                                type="button"
                                @click="switchMode('instant')"
                                :class="[
                                    'flex-1 sm:flex-initial px-3 py-1.5 text-xs font-medium rounded-md transition-colors text-center',
                                    createMode === 'instant'
                                        ? 'bg-white text-gray-900 shadow-xs'
                                        : 'text-gray-600 hover:text-gray-900'
                                ]"
                            >
                                {{ $t('dashboard.startNow') }}
                            </button>
                            <button
                                type="button"
                                @click="switchMode('scheduled')"
                                :class="[
                                    'flex-1 sm:flex-initial px-3 py-1.5 text-xs font-medium rounded-md transition-colors text-center',
                                    createMode === 'scheduled'
                                        ? 'bg-white text-gray-900 shadow-xs'
                                        : 'text-gray-600 hover:text-gray-900'
                                ]"
                            >
                                {{ $t('dashboard.scheduleForLater') }}
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="submitCreate" class="space-y-4">
                        <!-- Meeting Title -->
                        <div>
                            <InputLabel for="create-title" :value="$t('dashboard.meetingTitle')" />
                            <TextInput
                                id="create-title"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="createForm.title"
                                :placeholder="t('dashboard.meetingTitlePlaceholder')"
                                required
                                autofocus
                            />
                            <InputError class="mt-1" :message="createForm.errors.title" />
                        </div>

                        <!-- Scheduled Fields (Conditional) -->
                        <div v-if="createMode === 'scheduled'" class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 bg-gray-50 p-4 rounded-lg border border-gray-200">
                            <div>
                                <InputLabel for="starts_at" :value="$t('dashboard.dateTime')" />
                                <input
                                    id="starts_at"
                                    type="datetime-local"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    dir="ltr"
                                    v-model="createForm.starts_at"
                                    :min="minDateTime"
                                    required
                                />
                                <div class="mt-1.5 flex items-center text-xs text-gray-500 flex-wrap gap-1">
                                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 me-1.5 shrink-0"></span>
                                    <span>{{ $t('dashboard.timezoneLabel') }} <strong class="text-gray-700 font-sans" dir="ltr">{{ userTimeZone }}</strong></span>
                                </div>
                                <InputError class="mt-1" :message="createForm.errors.starts_at" />
                            </div>

                            <div>
                                <InputLabel for="duration_minutes" :value="$t('dashboard.durationMinutes')" />
                                <TextInput
                                    id="duration_minutes"
                                    type="number"
                                    min="1"
                                    max="1440"
                                    step="5"
                                    class="mt-1 block w-full text-start"
                                    dir="ltr"
                                    v-model="createForm.duration_minutes"
                                    required
                                />
                                <p class="mt-1.5 text-xs text-gray-500">
                                    {{ $t('dashboard.durationHint') }}
                                </p>
                                <InputError class="mt-1" :message="createForm.errors.duration_minutes" />
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end pt-2">
                            <PrimaryButton :disabled="createForm.processing">
                                {{ createMode === 'instant' ? $t('dashboard.startMeetingNow') : $t('dashboard.scheduleMeeting') }}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Upcoming Scheduled Meetings Section -->
                <div class="bg-white p-4 sm:p-6 shadow-xs rounded-lg">
                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-gray-100">
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-medium text-gray-900">
                                {{ $t('dashboard.upcomingMeetings') }}
                            </h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800">
                                {{ upcomingMeetings.length }}
                            </span>
                        </div>
                    </div>

                    <div v-if="upcomingMeetings.length === 0" class="text-center py-8 text-gray-500 text-sm">
                        {{ $t('dashboard.noUpcoming') }}
                    </div>

                    <div v-else class="divide-y divide-gray-100">
                        <div
                            v-for="meeting in upcomingMeetings"
                            :key="meeting.id"
                            class="py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-gray-50/50 rounded-md px-2 transition-colors"
                        >
                            <div class="space-y-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h4 class="text-base font-semibold text-gray-900">
                                        {{ meeting.title }}
                                    </h4>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        {{ $t('dashboard.upcomingBadge') }}
                                    </span>
                                </div>
                                <div class="text-xs text-gray-600 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span>
                                        📅 <strong>{{ formatMeetingDisplay(meeting.starts_at) }}</strong> <span dir="ltr">({{ userTimeZone }})</span>
                                    </span>
                                    <span>•</span>
                                    <span>⏱️ {{ meeting.duration_minutes }} {{ $t('common.mins') }}</span>
                                    <span>•</span>
                                    <span class="font-mono text-gray-500" dir="ltr">{{ $t('common.room') }}: {{ meeting.room_name }}</span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <button
                                    type="button"
                                    @click="copyLink(meeting)"
                                    class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-xs text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500"
                                >
                                    {{ copiedId === meeting.id ? $t('common.copied') : $t('common.copyLink') }}
                                </button>
                                <Link
                                    :href="meeting.url"
                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500"
                                >
                                    {{ $t('dashboard.joinMeeting') }}
                                </Link>
                                <button
                                    type="button"
                                    @click="openEditModal(meeting)"
                                    class="inline-flex items-center px-2.5 py-1.5 border border-gray-300 text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500"
                                >
                                    {{ $t('common.edit') }}
                                </button>
                                <button
                                    type="button"
                                    @click="deleteMeeting(meeting)"
                                    class="inline-flex items-center px-2.5 py-1.5 border border-red-200 text-xs font-medium rounded-md text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-red-500"
                                >
                                    {{ $t('common.delete') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Instant & Past Meetings Section -->
                <div class="bg-white p-4 sm:p-6 shadow-xs rounded-lg">
                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-gray-100">
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-medium text-gray-900">
                                {{ $t('dashboard.recentMeetings') }}
                            </h3>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                {{ recentMeetings.length }}
                            </span>
                        </div>
                    </div>

                    <div v-if="recentMeetings.length === 0" class="text-center py-8 text-gray-500 text-sm">
                        {{ $t('dashboard.noRecent') }}
                    </div>

                    <div v-else class="divide-y divide-gray-100">
                        <div
                            v-for="meeting in recentMeetings"
                            :key="meeting.id"
                            class="py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-gray-50/50 rounded-md px-2 transition-colors"
                        >
                            <div class="space-y-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h4 class="text-base font-semibold text-gray-900">
                                        {{ meeting.title }}
                                    </h4>
                                    <span
                                        :class="[
                                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border',
                                            meeting.is_scheduled
                                                ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                : 'bg-gray-50 text-gray-600 border-gray-200'
                                        ]"
                                    >
                                        {{ meeting.is_scheduled ? $t('dashboard.pastScheduledBadge') : $t('dashboard.instantBadge') }}
                                    </span>
                                </div>
                                <div class="text-xs text-gray-500 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span v-if="meeting.is_scheduled">
                                        {{ $t('dashboard.scheduledFor') }} <strong>{{ formatMeetingDisplay(meeting.starts_at) }}</strong> <span dir="ltr">({{ userTimeZone }})</span> • {{ meeting.duration_minutes }} {{ $t('common.mins') }}
                                    </span>
                                    <span v-else>
                                        {{ $t('dashboard.created') }} {{ formatMeetingDisplay(meeting.created_at) }}
                                    </span>
                                    <span>•</span>
                                    <span class="font-mono text-gray-500" dir="ltr">{{ $t('common.room') }}: {{ meeting.room_name }}</span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <button
                                    type="button"
                                    @click="copyLink(meeting)"
                                    class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-xs text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500"
                                >
                                    {{ copiedId === meeting.id ? $t('common.copied') : $t('common.copyLink') }}
                                </button>
                                <Link
                                    :href="meeting.url"
                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500"
                                >
                                    {{ $t('dashboard.joinMeeting') }}
                                </Link>
                                <button
                                    type="button"
                                    @click="openEditModal(meeting)"
                                    class="inline-flex items-center px-2.5 py-1.5 border border-gray-300 text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500"
                                >
                                    {{ $t('common.edit') }}
                                </button>
                                <button
                                    type="button"
                                    @click="deleteMeeting(meeting)"
                                    class="inline-flex items-center px-2.5 py-1.5 border border-red-200 text-xs font-medium rounded-md text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-red-500"
                                >
                                    {{ $t('common.delete') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Meeting Modal -->
        <Modal :show="isEditModalOpen" @close="closeEditModal" maxWidth="lg">
            <div class="p-4 sm:p-6 max-h-[85vh] overflow-y-auto">
                <h3 class="text-lg font-medium text-gray-900 border-b border-gray-100 pb-3 mb-4">
                    {{ $t('dashboard.editModalTitle', { title: editingMeeting?.title }) }}
                </h3>

                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div>
                        <InputLabel for="edit-title" :value="$t('dashboard.meetingTitle')" />
                        <TextInput
                            id="edit-title"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="editForm.title"
                            required
                        />
                        <InputError class="mt-1" :message="editForm.errors.title" />
                    </div>

                    <!-- Schedule Toggle -->
                    <div class="flex items-center gap-2 pt-1">
                        <input
                            id="edit-is-scheduled"
                            type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 shadow-xs focus:ring-indigo-500"
                            v-model="editForm.is_scheduled"
                        />
                        <label for="edit-is-scheduled" class="text-sm font-medium text-gray-700 select-none">
                            {{ $t('dashboard.scheduleToggle') }}
                        </label>
                    </div>

                    <!-- Scheduled Options if enabled -->
                    <div v-if="editForm.is_scheduled" class="space-y-4 bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <div>
                            <InputLabel for="edit-starts-at" :value="$t('dashboard.dateTime')" />
                            <input
                                id="edit-starts-at"
                                type="datetime-local"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                dir="ltr"
                                v-model="editForm.starts_at"
                                required
                            />
                            <div class="mt-1.5 flex items-center text-xs text-gray-500 flex-wrap gap-1">
                                <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 me-1.5 shrink-0"></span>
                                <span>{{ $t('dashboard.timezoneLabel') }} <strong class="text-gray-700 font-sans" dir="ltr">{{ userTimeZone }}</strong></span>
                            </div>
                            <InputError class="mt-1" :message="editForm.errors.starts_at" />
                        </div>

                        <div>
                            <InputLabel for="edit-duration" :value="$t('dashboard.durationMinutes')" />
                            <TextInput
                                id="edit-duration"
                                type="number"
                                min="1"
                                max="1440"
                                step="5"
                                class="mt-1 block w-full text-start"
                                dir="ltr"
                                v-model="editForm.duration_minutes"
                                required
                            />
                            <InputError class="mt-1" :message="editForm.errors.duration_minutes" />
                        </div>
                    </div>

                    <div class="pt-2 text-xs text-gray-500" dir="auto">
                        {{ $t('dashboard.roomPreserved', { room: editingMeeting?.room_name }) }}
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                        <SecondaryButton @click="closeEditModal" :disabled="editForm.processing">
                            {{ $t('common.cancel') }}
                        </SecondaryButton>
                        <PrimaryButton :disabled="editForm.processing">
                            {{ $t('dashboard.saveChanges') }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
