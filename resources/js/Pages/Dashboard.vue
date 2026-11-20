<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: { type: Object, required: true },
    recent: { type: Array, required: true },
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <div
                    v-if="$page.props.flash.message"
                    class="rounded-md bg-emerald-50 p-4 text-sm text-emerald-700 ring-1 ring-emerald-200"
                >
                    {{ $page.props.flash.message }}
                </div>

                <!-- Stat cards -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                        <div class="text-sm font-medium text-gray-500">Credit balance</div>
                        <div class="mt-1 text-3xl font-bold text-gray-900">
                            ${{ stats.balance_usd }}
                        </div>
                        <Link
                            :href="route('credits.purchase')"
                            class="mt-3 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            Top up credits &rarr;
                        </Link>
                    </div>
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                        <div class="text-sm font-medium text-gray-500">Generations</div>
                        <div class="mt-1 text-3xl font-bold text-gray-900">
                            {{ stats.total_generations }}
                        </div>
                        <p class="mt-3 text-sm text-gray-500">
                            {{ stats.succeeded }} succeeded &middot; {{ stats.failed }} failed
                            &middot; {{ stats.in_flight }} in flight
                        </p>
                    </div>
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                        <div class="text-sm font-medium text-gray-500">Lifetime spend</div>
                        <div class="mt-1 text-3xl font-bold text-gray-900">
                            ${{ stats.lifetime_charge }}
                        </div>
                        <p class="mt-3 text-sm text-gray-500">
                            provider cost ${{ stats.lifetime_provider_cost }}
                        </p>
                    </div>
                </div>

                <!-- CTA -->
                <div class="flex items-center justify-between rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 p-6 shadow">
                    <div>
                        <div class="text-lg font-semibold text-white">Create a new image</div>
                        <div class="text-sm text-indigo-100">
                            Estimates are free — you only pay for successful generations (provider cost &times; 1.25).
                        </div>
                    </div>
                    <Link
                        :href="route('generate')"
                        class="rounded-lg bg-white px-5 py-2.5 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50"
                    >
                        Start generating
                    </Link>
                </div>

                <!-- Recent generations -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200/60">
                    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                        <h3 class="font-semibold text-gray-800">Recent generations</h3>
                        <Link
                            :href="route('generations.index')"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            View history &rarr;
                        </Link>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100 text-sm">
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="recent.length === 0">
                                <td class="px-6 py-8 text-center text-gray-400">
                                    No generations yet — create your first one!
                                </td>
                            </tr>
                            <tr v-for="g in recent" :key="g.id" class="hover:bg-gray-50/60">
                                <td class="px-6 py-3">
                                    <img
                                        v-if="g.image_url && g.status === 'succeeded'"
                                        :src="g.image_url"
                                        class="h-10 w-10 rounded-md object-cover ring-1 ring-gray-200"
                                        alt=""
                                    />
                                    <div v-else class="flex h-10 w-10 items-center justify-center rounded-md bg-gray-100 text-gray-400">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A1.5 1.5 0 0021.75 19.5V4.5A1.5 1.5 0 0020.25 3H3.75A1.5 1.5 0 002.25 4.5v15A1.5 1.5 0 003.75 21z" />
                                        </svg>
                                    </div>
                                </td>
                                <td class="px-3 py-3 max-w-md">
                                    <Link :href="route('generations.show', g.uuid)" class="text-gray-900 hover:text-indigo-600">
                                        {{ g.prompt_preview }}
                                    </Link>
                                </td>
                                <td class="px-3 py-3 text-gray-500">{{ g.size }} / {{ g.quality }}</td>
                                <td class="px-3 py-3"><StatusBadge :status="g.status" /></td>
                                <td class="px-6 py-3 text-right text-gray-500">
                                    {{ g.actual_charge ? '$' + g.actual_charge : (g.est_charge_max ? '~$' + g.est_charge_max : '—') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
