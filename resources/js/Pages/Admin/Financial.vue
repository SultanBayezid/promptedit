<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    daily: { type: Array, required: true },
    perUser: { type: Array, required: true },
    perGeneration: { type: Array, required: true },
    totals: { type: Object, required: true },
    filters: { type: Object, required: true },
    reconciliation: { type: Object, default: null },
});

const form = useForm({
    from: props.filters.from,
    to: props.filters.to,
});

const applyDates = () => {
    router.get(route('admin.financial'), { from: form.from, to: form.to }, { preserveState: true });
};

const reconcile = () => {
    form.post(route('admin.financial.reconcile'), {
        data: { from: form.from, to: form.to },
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Financial reports" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Financial reports</h2>
                <Link
                    :href="route('admin.panel')"
                    class="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                >
                    &larr; Admin panel
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <!-- Date filter -->
                <div class="flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 shadow ring-1 ring-gray-200/60">
                    <div>
                        <label class="block text-xs font-medium uppercase text-gray-400">From</label>
                        <input v-model="form.from" type="date"
                            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase text-gray-400">To</label>
                        <input v-model="form.to" type="date"
                            class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <button
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        @click="applyDates"
                    >
                        Apply
                    </button>
                    <button
                        class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 disabled:opacity-50"
                        :disabled="form.processing"
                        @click="reconcile"
                    >
                        {{ form.processing ? 'Reconciling…' : 'Reconcile with GET /v1/usage' }}
                    </button>

                    <div class="ms-auto grid grid-cols-4 gap-4 text-right text-sm">
                        <div>
                            <div class="text-xs uppercase text-gray-400">Generations</div>
                            <div class="font-semibold text-gray-800">{{ totals.generations }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-gray-400">Provider cost</div>
                            <div class="font-semibold text-gray-800">${{ totals.provider_cost }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-gray-400">Revenue</div>
                            <div class="font-semibold text-emerald-600">${{ totals.revenue }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-gray-400">Profit</div>
                            <div class="font-semibold text-indigo-600">${{ totals.profit }}</div>
                        </div>
                    </div>
                </div>

                <!-- Reconciliation result -->
                <div v-if="reconciliation" class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                    <template v-if="reconciliation.error">
                        <div class="rounded-md bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200">
                            {{ reconciliation.error }}
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-gray-800">
                                Provider reconciliation
                                <span class="ml-2 text-xs font-normal text-gray-400">
                                    {{ reconciliation.from }} &ndash; {{ reconciliation.to }} &middot; fetched {{ new Date(reconciliation.fetched_at).toLocaleTimeString() }}
                                </span>
                            </h3>
                            <span
                                class="rounded-full px-3 py-1 text-xs font-semibold ring-1"
                                :class="reconciliation.totals.matches
                                    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                    : 'bg-amber-50 text-amber-700 ring-amber-200'"
                            >
                                {{ reconciliation.totals.matches ? 'MATCHES' : 'MISMATCH' }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">
                            Our recorded usage vs the provider's own report:
                            ours ${{ reconciliation.totals.our_cost_usd }}
                            &middot; provider ${{ reconciliation.totals.provider_cost_usd }}
                            &middot; delta ${{ reconciliation.totals.delta_usd }}
                        </p>
                        <table class="mt-3 min-w-full divide-y divide-gray-100 text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase text-gray-400">
                                    <th class="py-2">Date</th>
                                    <th class="py-2 text-right">Our gens</th>
                                    <th class="py-2 text-right">Provider gens</th>
                                    <th class="py-2 text-right">Our cost</th>
                                    <th class="py-2 text-right">Provider cost</th>
                                    <th class="py-2 text-right">Delta</th>
                                    <th class="py-2 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="d in reconciliation.days" :key="d.date">
                                    <td class="py-2 font-mono text-xs">{{ d.date }}</td>
                                    <td class="py-2 text-right">{{ d.our_generations }}</td>
                                    <td class="py-2 text-right">{{ d.provider_generations }}</td>
                                    <td class="py-2 text-right font-mono text-xs">${{ d.our_cost_usd }}</td>
                                    <td class="py-2 text-right font-mono text-xs">${{ d.provider_cost_usd }}</td>
                                    <td class="py-2 text-right font-mono text-xs"
                                        :class="d.cost_delta === 0 ? 'text-gray-400' : 'text-amber-600'">
                                        ${{ d.cost_delta }}
                                    </td>
                                    <td class="py-2 text-right">
                                        <span v-if="d.matches" class="text-emerald-600">&#10003;</span>
                                        <span v-else class="text-amber-600" :title="JSON.stringify(d.token_deltas)">&#9888;</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                </div>

                <!-- Daily P&L -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="font-semibold text-gray-800">Daily profit &amp; loss</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold text-gray-500">Day</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Generations</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Provider cost</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Revenue</th>
                                <th class="px-6 py-3 text-right font-semibold text-gray-500">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="daily.length === 0">
                                <td colspan="5" class="px-6 py-8 text-center text-gray-400">No completed generations in this period.</td>
                            </tr>
                            <tr v-for="d in daily" :key="d.day" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3 font-mono text-xs text-gray-600">{{ d.day }}</td>
                                <td class="px-3 py-3 text-right text-gray-600">{{ d.generations }}</td>
                                <td class="px-3 py-3 text-right font-mono text-xs text-gray-600">${{ d.provider_cost }}</td>
                                <td class="px-3 py-3 text-right font-mono text-xs text-emerald-600">${{ d.revenue }}</td>
                                <td class="px-6 py-3 text-right font-mono text-xs font-semibold text-indigo-600">${{ d.profit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Per user -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="font-semibold text-gray-800">Per-user breakdown</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold text-gray-500">User</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Generations</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Provider cost</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Revenue</th>
                                <th class="px-6 py-3 text-right font-semibold text-gray-500">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="perUser.length === 0">
                                <td colspan="5" class="px-6 py-8 text-center text-gray-400">No data in this period.</td>
                            </tr>
                            <tr v-for="u in perUser" :key="u.id" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3">
                                    <div class="font-medium text-gray-800">{{ u.name }}</div>
                                    <div class="text-xs text-gray-400">{{ u.email }}</div>
                                </td>
                                <td class="px-3 py-3 text-right text-gray-600">{{ u.generations }}</td>
                                <td class="px-3 py-3 text-right font-mono text-xs text-gray-600">${{ u.provider_cost }}</td>
                                <td class="px-3 py-3 text-right font-mono text-xs text-emerald-600">${{ u.revenue }}</td>
                                <td class="px-6 py-3 text-right font-mono text-xs font-semibold text-indigo-600">${{ u.profit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Per generation -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="font-semibold text-gray-800">Per-generation breakdown (latest 100)</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold text-gray-500">User</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Prompt</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Tokens (t/i/o/r)</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Provider cost</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Charge</th>
                                <th class="px-6 py-3 text-right font-semibold text-gray-500">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="perGeneration.length === 0">
                                <td colspan="6" class="px-6 py-8 text-center text-gray-400">No completed generations in this period.</td>
                            </tr>
                            <tr v-for="g in perGeneration" :key="g.id" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3 text-gray-600">{{ g.user_name }}</td>
                                <td class="px-3 py-3 max-w-xs">
                                    <Link :href="route('generations.show', g.uuid)" class="text-gray-800 hover:text-indigo-600">
                                        {{ g.prompt_preview }}
                                    </Link>
                                </td>
                                <td class="px-3 py-3 text-right font-mono text-xs text-gray-500">
                                    {{ g.tokens.input_text }}/{{ g.tokens.input_image }}/{{ g.tokens.output }}/{{ g.tokens.reasoning }}
                                </td>
                                <td class="px-3 py-3 text-right font-mono text-xs text-gray-600">${{ g.actual_provider_cost }}</td>
                                <td class="px-3 py-3 text-right font-mono text-xs text-emerald-600">${{ g.actual_charge }}</td>
                                <td class="px-6 py-3 text-right font-mono text-xs font-semibold text-indigo-600">
                                    ${{ (parseFloat(g.actual_charge) - parseFloat(g.actual_provider_cost)).toFixed(9) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
