<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    generations: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
});

const q = ref(props.filters.q);
const statusFilter = ref(props.filters.status);
let searchTimer = null;

const statuses = [
    { value: '', label: 'All' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
    { value: 'processing', label: 'Processing' },
    { value: 'queued', label: 'Queued' },
];

function applyFilter(status) {
    statusFilter.value = status;
    router.get(route('generations.index'), { q: q.value, status }, { preserveState: true, replace: true });
}

function search() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('generations.index'), { q: q.value, status: statusFilter.value }, { preserveState: true, replace: true });
    }, 400);
}
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Generation history</h2>
                <Link
                    :href="route('generate')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    New generation
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="s in statuses"
                        :key="s.value"
                        class="rounded-full px-3.5 py-1.5 text-sm font-medium ring-1 transition"
                        :class="statusFilter === s.value
                            ? 'bg-indigo-600 text-white ring-indigo-600'
                            : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50'"
                        @click="applyFilter(s.value)"
                    >
                        {{ s.label }}
                        <span class="ml-1 text-xs opacity-60" v-if="s.value">{{ stats[s.value.replace('processing', 'in_flight') === 'in_flight' ? 'in_flight' : s.value] ?? '' }}</span>
                    </button>
                    <div class="ms-auto">
                        <input
                            v-model="q"
                            @input="search"
                            type="search"
                            placeholder="Search prompts…"
                            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold text-gray-500">Image</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Prompt</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Settings</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Status</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Provider cost</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Charged</th>
                                <th class="px-6 py-3 text-right font-semibold text-gray-500">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="generations.data.length === 0">
                                <td colspan="7" class="px-6 py-10 text-center text-gray-400">
                                    No generations found.
                                </td>
                            </tr>
                            <tr v-for="g in generations.data" :key="g.id" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3">
                                    <img
                                        v-if="g.image_url && g.status === 'succeeded'"
                                        :src="g.image_url"
                                        class="h-11 w-11 rounded-md object-cover ring-1 ring-gray-200"
                                        alt=""
                                    />
                                    <div v-else class="flex h-11 w-11 items-center justify-center rounded-md bg-gray-100 text-gray-400">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21z" />
                                        </svg>
                                    </div>
                                </td>
                                <td class="px-3 py-3 max-w-sm">
                                    <Link :href="route('generations.show', g.uuid)" class="text-gray-900 hover:text-indigo-600">
                                        {{ g.prompt_preview }}
                                    </Link>
                                </td>
                                <td class="px-3 py-3 text-gray-500">{{ g.size }}<br />{{ g.quality }}</td>
                                <td class="px-3 py-3"><StatusBadge :status="g.status" /></td>
                                <td class="px-3 py-3 text-right text-gray-500">
                                    {{ g.actual_provider_cost ? '$' + g.actual_provider_cost : '—' }}
                                </td>
                                <td class="px-3 py-3 text-right font-medium text-gray-800">
                                    <span v-if="g.actual_charge">${{ g.actual_charge }}</span>
                                    <span v-else-if="g.est_charge_max" class="text-gray-400">~${{ g.est_charge_max }}</span>
                                    <span v-else>—</span>
                                </td>
                                <td class="px-6 py-3 text-right text-gray-500">
                                    {{ new Date(g.created_at).toLocaleString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="generations.last_page > 1" class="flex items-center justify-between border-t border-gray-100 px-6 py-4 text-sm">
                        <span class="text-gray-500">
                            Page {{ generations.current_page }} of {{ generations.last_page }}
                            ({{ generations.total }} total)
                        </span>
                        <div class="flex gap-2">
                            <Link
                                v-if="generations.prev_page_url"
                                :href="generations.prev_page_url"
                                class="rounded-md bg-white px-3 py-1.5 ring-1 ring-gray-200 hover:bg-gray-50"
                                preserve-scroll
                            >
                                Previous
                            </Link>
                            <Link
                                v-if="generations.next_page_url"
                                :href="generations.next_page_url"
                                class="rounded-md bg-white px-3 py-1.5 ring-1 ring-gray-200 hover:bg-gray-50"
                                preserve-scroll
                            >
                                Next
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
