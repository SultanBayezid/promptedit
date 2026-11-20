<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, computed } from 'vue';

const props = defineProps({
    generation: { type: Object, required: true },
    isAdmin: { type: Boolean, default: false },
});

const inFlight = computed(() => ['queued', 'processing'].includes(props.generation.status));

let timer = null;
onMounted(() => {
    if (inFlight.value) {
        timer = setInterval(() => {
            router.reload({ only: ['generation'] });
        }, 5000);
    }
});
onBeforeUnmount(() => clearInterval(timer));

function fmtDate(iso) {
    return iso ? new Date(iso).toLocaleString() : '—';
}
</script>

<template>
    <Head :title="`Generation ${generation.uuid.slice(0, 8)}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Generation detail</h2>
                <StatusBadge :status="generation.status" />
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="inFlight"
                    class="flex items-center gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-700 ring-1 ring-amber-200"
                >
                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-amber-500 border-t-transparent"></span>
                    Your generation is {{ generation.status }} — this page refreshes automatically.
                    Webhooks notify us the moment the provider finishes; polling is our fallback.
                </div>
                <div
                    v-if="generation.status === 'failed'"
                    class="rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200"
                >
                    <strong>Generation failed (not charged).</strong>
                    <span v-if="generation.error_message" class="ml-1">{{ generation.error_message }}</span>
                </div>
                <div
                    v-if="$page.props.flash.message"
                    class="rounded-md bg-emerald-50 p-4 text-sm text-emerald-700 ring-1 ring-emerald-200"
                >
                    {{ $page.props.flash.message }}
                </div>

                <div class="grid gap-6 lg:grid-cols-5">
                    <!-- Image -->
                    <div class="lg:col-span-2">
                        <div class="overflow-hidden rounded-xl bg-white p-4 shadow ring-1 ring-gray-200/60">
                            <a v-if="generation.image_url" :href="generation.image_url" target="_blank">
                                <img
                                    :src="generation.image_url"
                                    class="mx-auto max-h-[420px] w-auto rounded-lg object-contain"
                                    alt="Generated image"
                                />
                            </a>
                            <div v-else class="flex aspect-square items-center justify-center rounded-lg bg-gray-50 text-gray-300">
                                <svg class="h-14 w-14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1"
                                        d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="space-y-6 lg:col-span-3">
                        <!-- Prompt -->
                        <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Prompt</h3>
                            <p class="mt-2 whitespace-pre-wrap text-gray-800">{{ generation.prompt }}</p>
                            <div class="mt-4 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                                <div>
                                    <div class="text-gray-400">Size</div>
                                    <div class="font-medium text-gray-700">{{ generation.size }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-400">Quality</div>
                                    <div class="font-medium text-gray-700 capitalize">{{ generation.quality }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-400">Reference images</div>
                                    <div class="font-medium text-gray-700">{{ generation.reference_images.length }}</div>
                                </div>
                                <div>
                                    <div class="text-gray-400">Provider ID</div>
                                    <div class="truncate font-mono text-xs text-gray-700" :title="generation.uuid">
                                        {{ generation.uuid.slice(0, 13) }}…
                                    </div>
                                </div>
                            </div>
                            <ul v-if="generation.reference_images.length" class="mt-3 space-y-1">
                                <li v-for="(url, i) in generation.reference_images" :key="i"
                                    class="truncate rounded bg-gray-50 px-2 py-1 text-xs text-indigo-600 ring-1 ring-gray-100">
                                    {{ url }}
                                </li>
                            </ul>
                        </div>

                        <!-- Costs -->
                        <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Costs</h3>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                <div class="rounded-lg bg-gray-50 p-4 ring-1 ring-gray-100">
                                    <div class="text-xs font-medium uppercase text-gray-400">Estimated (before submit)</div>
                                    <dl class="mt-2 space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500">Provider cost</dt>
                                            <dd>${{ generation.est_provider_cost_min }} &ndash; ${{ generation.est_provider_cost_max }}</dd>
                                        </div>
                                        <div class="flex justify-between font-medium">
                                            <dt class="text-gray-600">Charge (&times;1.25)</dt>
                                            <dd>${{ generation.est_charge_min }} &ndash; ${{ generation.est_charge_max }}</dd>
                                        </div>
                                    </dl>
                                </div>
                                <div class="rounded-lg p-4 ring-1"
                                    :class="generation.status === 'succeeded' ? 'bg-emerald-50 ring-emerald-100' : 'bg-gray-50 ring-gray-100'">
                                    <div class="text-xs font-medium uppercase"
                                        :class="generation.status === 'succeeded' ? 'text-emerald-500' : 'text-gray-400'">
                                        Actual (after completion)
                                    </div>
                                    <dl class="mt-2 space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <dt class="text-gray-500">Provider cost</dt>
                                            <dd>{{ generation.actual_provider_cost ? '$' + generation.actual_provider_cost : '—' }}</dd>
                                        </div>
                                        <div class="flex justify-between font-semibold text-gray-700">
                                            <dt>Charged</dt>
                                            <dd>{{ generation.actual_charge !== null ? '$' + generation.actual_charge : (generation.status === 'failed' ? '$0.000000000 (free)' : '—') }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>

                            <div v-if="generation.cost_breakdown" class="mt-4 border-t border-gray-100 pt-3">
                                <div class="text-xs font-medium uppercase text-gray-400">Actual cost breakdown</div>
                                <dl class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                    <div v-for="row in generation.cost_breakdown" :key="row.label" class="flex justify-between">
                                        <dt class="text-gray-500">{{ row.label }}</dt>
                                        <dd class="font-mono text-xs text-gray-700">${{ row.usd }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Tokens + timeline -->
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Tokens</h3>
                                <dl class="mt-3 space-y-1.5 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Input text</dt>
                                        <dd>{{ generation.tokens.input_text ?? '—' }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Input images</dt>
                                        <dd>{{ generation.tokens.input_image ?? '—' }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Output (est. {{ generation.tokens.est_output }})</dt>
                                        <dd>{{ generation.tokens.output ?? '—' }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Reasoning</dt>
                                        <dd>{{ generation.tokens.reasoning ?? '—' }}</dd>
                                    </div>
                                </dl>
                            </div>
                            <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Timeline</h3>
                                <dl class="mt-3 space-y-1.5 text-sm">
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Created</dt>
                                        <dd>{{ fmtDate(generation.created_at) }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Submitted</dt>
                                        <dd>{{ fmtDate(generation.submitted_at) }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-500">Completed</dt>
                                        <dd>{{ fmtDate(generation.completed_at) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Ledger -->
                        <div v-if="generation.ledger && generation.ledger.length" class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-400">Billing ledger</h3>
                            <table class="mt-3 min-w-full text-sm">
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="t in generation.ledger" :key="t.id">
                                        <td class="py-1.5 pr-4 font-medium text-gray-700">{{ t.type.replace('_', ' ') }}</td>
                                        <td class="py-1.5 pr-4 text-right font-mono"
                                            :class="t.amount.startsWith('-') ? 'text-red-600' : 'text-emerald-600'">
                                            {{ t.amount.startsWith('-') ? '' : '+' }}${{ t.amount }}
                                        </td>
                                        <td class="py-1.5 pr-4 text-right text-gray-400">&rarr; ${{ t.balance_after }}</td>
                                        <td class="py-1.5 text-right text-gray-400">{{ fmtDate(t.created_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
