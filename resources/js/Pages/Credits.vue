<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    balance: { type: Number, required: true },
    balance_usd: { type: String, required: true },
    transactions: { type: Object, required: true },
    presets: { type: Array, required: true },
});

const typeLabels = {
    purchase: 'Purchase',
    hold: 'Hold placed',
    settle: 'Settlement',
    release_hold: 'Hold released (failure)',
};

function fmtDate(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <Head title="Credits" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Credits</h2>
                <Link
                    :href="route('credits.purchase')"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Buy credits
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="$page.props.flash.message"
                    class="rounded-md bg-emerald-50 p-4 text-sm text-emerald-700 ring-1 ring-emerald-200"
                >
                    {{ $page.props.flash.message }}
                </div>

                <div class="rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 p-8 text-white shadow">
                    <div class="text-sm font-medium text-indigo-100">Current balance</div>
                    <div class="mt-1 text-4xl font-bold">${{ balance_usd }}</div>
                    <p class="mt-3 max-w-xl text-sm text-indigo-100">
                        Credits are held at the maximum estimated charge when you submit a generation.
                        On success you pay the actual provider cost &times; 1.25; on failure the hold is
                        returned in full — failed generations are always free.
                    </p>
                </div>

                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="font-semibold text-gray-800">Transaction ledger</h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50/60">
                            <tr>
                                <th class="px-6 py-3 text-left font-semibold text-gray-500">Type</th>
                                <th class="px-3 py-3 text-left font-semibold text-gray-500">Details</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Amount</th>
                                <th class="px-3 py-3 text-right font-semibold text-gray-500">Balance after</th>
                                <th class="px-6 py-3 text-right font-semibold text-gray-500">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="transactions.data.length === 0">
                                <td colspan="5" class="px-6 py-10 text-center text-gray-400">
                                    No transactions yet.
                                </td>
                            </tr>
                            <tr v-for="t in transactions.data" :key="t.id" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3 font-medium text-gray-700">{{ typeLabels[t.type] ?? t.type }}</td>
                                <td class="px-3 py-3 text-gray-400">
                                    <Link v-if="t.generation_uuid" class="text-indigo-500 hover:text-indigo-600"
                                        :href="route('generations.show', t.generation_uuid)">
                                        generation #{{ t.generation_id }}
                                    </Link>
                                    <span v-else-if="t.memo">{{ t.memo }}</span>
                                </td>
                                <td class="px-3 py-3 text-right font-mono" :class="t.amount.startsWith('-') ? 'text-red-600' : 'text-emerald-600'">
                                    {{ t.amount.startsWith('-') ? '' : '+' }}${{ t.amount }}
                                </td>
                                <td class="px-3 py-3 text-right font-mono text-gray-500">${{ t.balance_after }}</td>
                                <td class="px-6 py-3 text-right text-gray-500">{{ fmtDate(t.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="transactions.last_page > 1" class="flex items-center justify-between border-t border-gray-100 px-6 py-4 text-sm">
                        <span class="text-gray-500">
                            Page {{ transactions.current_page }} of {{ transactions.last_page }}
                        </span>
                        <div class="flex gap-2">
                            <Link v-if="transactions.prev_page_url" :href="transactions.prev_page_url" preserve-scroll
                                class="rounded-md bg-white px-3 py-1.5 ring-1 ring-gray-200 hover:bg-gray-50">Previous</Link>
                            <Link v-if="transactions.next_page_url" :href="transactions.next_page_url" preserve-scroll
                                class="rounded-md bg-white px-3 py-1.5 ring-1 ring-gray-200 hover:bg-gray-50">Next</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
