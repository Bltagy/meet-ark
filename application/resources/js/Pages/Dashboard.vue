<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    meetings: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    title: '',
});

const copiedId = ref(null);

const submit = () => {
    form.post(route('meetings.store'), {
        onSuccess: () => form.reset(),
    });
};

const copyLink = (meeting) => {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(meeting.url).then(() => {
            copiedId.value = meeting.id;
            setTimeout(() => {
                copiedId.value = null;
            }, 2000);
        });
    }
};

const deleteMeeting = (meeting) => {
    if (confirm(`Delete meeting "${meeting.title}"?`)) {
        useForm({}).delete(route('meetings.destroy', meeting.id));
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <!-- Create Meeting Card -->
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">
                        Create New Meeting
                    </h3>
                    <form @submit.prevent="submit" class="flex flex-col sm:flex-row gap-4 items-start sm:items-end">
                        <div class="flex-1 w-full">
                            <InputLabel for="title" value="Meeting Title" />
                            <TextInput
                                id="title"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.title"
                                placeholder="e.g. Engineering Standup"
                                required
                                autofocus
                            />
                            <InputError class="mt-2" :message="form.errors.title" />
                        </div>
                        <PrimaryButton :disabled="form.processing" class="h-10">
                            Create Meeting
                        </PrimaryButton>
                    </form>
                </div>

                <!-- My Meetings Card -->
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">
                        My Meetings
                    </h3>

                    <div v-if="meetings.length === 0" class="text-center py-8 text-gray-500">
                        No meetings created yet. Create your first meeting above to get started.
                    </div>

                    <div v-else class="divide-y divide-gray-200">
                        <div
                            v-for="meeting in meetings"
                            :key="meeting.id"
                            class="py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                        >
                            <div>
                                <h4 class="text-base font-semibold text-gray-900">
                                    {{ meeting.title }}
                                </h4>
                                <p class="text-sm text-gray-500">
                                    Room ID: <span class="font-mono text-gray-700">{{ meeting.room_name }}</span> &bull;
                                    Created: {{ new Date(meeting.created_at).toLocaleString() }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="copyLink(meeting)"
                                    class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    {{ copiedId === meeting.id ? 'Copied!' : 'Copy Link' }}
                                </button>
                                <Link
                                    :href="route('meetings.show', meeting.id)"
                                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    Join Meeting
                                </Link>
                                <button
                                    type="button"
                                    @click="deleteMeeting(meeting)"
                                    class="inline-flex items-center px-2.5 py-1.5 border border-red-200 text-xs font-medium rounded text-red-600 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                                    title="Delete meeting"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
