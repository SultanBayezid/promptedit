<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    balance_usd: { type: String, required: true },
    presets: { type: Array, required: true },
});

const selected = ref(10);
const custom = ref('');
const form = useForm({ amount: 10 });

function choose(preset) {
    selected.value = preset;
    custom.value = '';
    form.amount = preset;
}

function submit() {
    if (custom.value) {
        const n = parseFloat(custom.value);
        if (isNaN(n) || n < 1) return;
        form.amount = Math.round(n * 100) / 100;
    }
    form.post(route('credits.purchase.store'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Buy credits" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Buy credits</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-xl bg-white p-8 shadow ring-1 ring-gray-200/60">
                    <div class="flex items-baseline justify-between">
                        <h3 class="text-lg font-semibold text-gray-800">Top up your balance</h3>
                        <span class="text-sm text-gray-400">Current: ${{ balance_usd }}</span>
                    </div>

                    <p class="mt-2 text-sm text-gray-500">
                        This is a <strong>simulated</strong> purchase flow (bonus feature — no real payment
                        is processed). Pick an amount and it will be credited instantly.
                    </p>

                    <div class="mt-6 grid grid-cols-3 gap-3 sm:grid-cols-5">
                        <button
                            v-for="p in presets"
                            :key="p"
                            type="button"
                            class="rounded-xl border-2 p-4 text-center transition"
                            :class="selected === p && !custom
                                ? 'border-indigo-600 bg-indigo-50'
                                : 'border-gray-200 hover:border-indigo-300'"
                            @click="choose(p)"
                        >
                            <div class="text-lg font-bold" :class="selected === p && !custom ? 'text-indigo-700' : 'text-gray-700'">
                                ${{ p }}
                            </div>
                        </button>
                    </div>

                    <div class="mt-5">
                        <InputLabel for="custom" value="Or a custom amount (USD, min $1)" />
                        <TextInput
                            id="custom"
                            v-model="custom"
                            type="number"
                            min="1" step="0.01" max="1000"
                            class="mt-1 block w-48"
                            placeholder="e.g. 7.50"
                            @input="selected = null"
                        />
                        <InputError class="mt-2" :message="form.errors.amount" />
                    </div>

                    <div class="mt-6 flex items-center justify-between border-t border-gray-100 pt-5">
                        <div class="text-sm text-gray-500">
                            You will be credited
                            <span class="font-semibold text-gray-800">
                                ${{ (custom ? (Math.round(parseFloat(custom || 0) * 100) / 100) : form.amount || 0).toFixed(2) }}
                            </span>
                        </div>
                        <PrimaryButton @click="submit" :disabled="form.processing">
                            <span v-if="form.processing">Processing…</span>
                            <span v-else>Complete purchase (simulated)</span>
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
