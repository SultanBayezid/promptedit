<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    totals: { type: Object, required: true },
    users: { type: Object, required: true },
    recent: { type: Array, required: true },
    webhookStats: { type: Object, required: true },
});
</script>

<template>
    <Head title="Admin panel" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Admin panel</h2>
                <Link
                    :href="route('admin.financial')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Financial reports
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <!-- Totals -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                        <div class="text-sm font-medium text-gray-500">Users</div>
                        <div class="mt-1 text-3xl font-bold text-gray-900">{{ totals.users }}</div>
                    </div>
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                        <div class="text-sm font-medium text-gray-500">Generations</div>
                        <div class="mt-1 text-3xl font-bold text-gray-900">{{ totals.generations }}</div>
                        <p class="mt-2 text-xs text-gray-400">
                            {{ totals.succeeded }} ok &middot; {{ totals.failed }} failed &middot; {{ totals.in_flight }} in flight
                        </p>
                    </div>
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                        <div class="text-sm font-medium text-gray-500">Revenue (charged)</div>
                        <div class="mt-1 text-2xl font-bold text-emerald-600">${{ totals.revenue }}</div>
                        <p class="mt-2 text-xs text-gray-400">provider cost ${{ totals.provider_cost }}</p>
                    </div>
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                        <div class="text-sm font-medium text-gray-500">Gross profit</div>
                        <div class="mt-1 text-2xl font-bold text-indigo-600">${{ totals.profit }}</div>
                        <p class="mt-2 text-xs text-gray-400">revenue &minus; provider cost</p>
                    </div>
                </div>

                <!-- Webhook health -->
                <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                    <h3 class="font-semibold text-gray-800">Webhook deliveries (audit log)</h3>
                    <div class="mt-3 flex flex-wrap gap-3 text-sm">
                        <span v-for="(n, status) in webhookStats" :key="status"
                            class="rounded-full px-3 py-1 ring-1"
                            :class="{
                                'bg-emerald-50 text-emerald-700 ring-emerald-200': status === 'processed',
                                'bg-gray-50 text-gray-600 ring-gray-200': status === 'duplicate',
                                'bg-red-50 text-red-700 ring-red-200': status === 'rejected' || status === 'errored',
                                'bg-amber-50 text-amber-700 ring-amber-200': status === 'unknown_generation',
                            }">
                            {{ status }}: <strong>{{ n }}</strong>
                        </span>
                        <span v-if="!Object.keys(webhookStats).length" class="text-gray-400">No webhook deliveries recorded yet.</span>
                    </div>
                </div>

                <!-- Recent generations -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="font-semibold text-gray-800">Latest generations</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold text-gray-500">User</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Prompt</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Status</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Cost</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Charge</th>
                                <th class="px-6 py-3 text-right font-semibold text-gray-500">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="recent.length === 0">
                                <td colspan="6" class="px-6 py-8 text-center text-gray-400">No generations yet.</td>
                            </tr>
                            <tr v-for="g in recent" :key="g.id" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3 text-gray-600">{{ g.user_name }}</td>
                                <td class="px-3 py-3 max-w-sm">
                                    <Link :href="route('generations.show', g.uuid)" class="text-gray-900 hover:text-indigo-600">
                                        {{ g.prompt_preview }}
                                    </Link>
                                </td>
                                <td class="px-3 py-3"><StatusBadge :status="g.status" /></td>
                                <td class="px-3 py-3 text-right text-gray-500">{{ g.actual_provider_cost ? '$' + g.actual_provider_cost : '—' }}</td>
                                <td class="px-3 py-3 text-right font-medium text-gray-800">{{ g.actual_charge ? '$' + g.actual_charge : '—' }}</td>
                                <td class="px-6 py-3 text-right text-gray-500">{{ new Date(g.created_at).toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Users -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="font-semibold text-gray-800">Users</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold text-gray-500">Name</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Email</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Admin</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Generations</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Balance</th>
                                <th class="px-6 py-3 text-right font-semibold text-gray-500">Spend</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="u in users.data" :key="u.id" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3 font-medium text-gray-800">{{ u.name }}</td>
                                <td class="px-3 py-3 text-gray-500">{{ u.email }}</td>
                                <td class="px-3 py-3">
                                    <span v-if="u.is_admin" class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-indigo-200">admin</span>
                                    <span v-else class="text-gray-300">—</span>
                                </td>
                                <td class="px-3 py-3 text-right text-gray-600">{{ u.generations_count }}</td>
                                <td class="px-3 py-3 text-right font-mono text-gray-600">${{ u.balance_usd }}</td>
                                <td class="px-6 py-3 text-right font-mono text-gray-800">${{ u.spend_usd }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
