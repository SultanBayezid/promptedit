<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
    maxPromptChars: { type: Number, required: true },
    maxReferenceImages: { type: Number, required: true },
    markup: { type: String, required: true },
    balance: { type: Number, required: true },
    recent: { type: Array, default: () => [] },
});

const form = useForm({
    prompt: '',
    size: props.sizes[0],
    quality: 'medium',
    reference_images: [],
});

const refUrl = ref('');
const estimate = ref(null);
const estimating = ref(false);
const estimateError = ref(null);
let estimateTimer = null;

const canEstimate = computed(() => form.prompt.trim().length > 0);

function addReferenceImage() {
    const url = refUrl.value.trim();
    if (!url) return;
    if (!/^https?:\/\//i.test(url)) {
        estimateError.value = 'Reference image URL must start with http:// or https://';
        return;
    }
    if (form.reference_images.length >= props.maxReferenceImages) return;
    form.reference_images.push(url);
    refUrl.value = '';
    estimateError.value = null;
}

function removeReferenceImage(index) {
    form.reference_images.splice(index, 1);
}

function requestEstimate() {
    clearTimeout(estimateTimer);
    if (!canEstimate.value) {
        estimate.value = null;
        return;
    }
    estimateTimer = setTimeout(async () => {
        estimating.value = true;
        estimateError.value = null;
        try {
            const { data } = await axios.post(route('generate.estimate'), {
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: form.reference_images,
            });
            estimate.value = data;
        } catch (e) {
            estimate.value = null;
            estimateError.value =
                e.response?.data?.error ?? 'Could not fetch pricing for the estimate. Please retry.';
        } finally {
            estimating.value = false;
        }
    }, 450);
}

watch(
    () => [form.prompt, form.size, form.quality, form.reference_images.length],
    () => requestEstimate(),
);

const submit = () => {
    if (!canEstimate.value) return;
    form.post(route('generate.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Generate an image</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl gap-6 px-4 sm:px-6 lg:flex lg:px-8">
                <!-- Form -->
                <div class="lg:w-2/3">
                    <form
                        @submit.prevent="submit"
                        class="space-y-6 rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60"
                    >
                        <div>
                            <InputLabel for="prompt" value="Prompt" />
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                rows="5"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                :placeholder="'Describe the image you want… (e.g. a watercolor lighthouse at dawn)'"
                                :maxlength="maxPromptChars"
                                required
                            />
                            <div class="mt-1 flex justify-between text-xs text-gray-400">
                                <span>Text token estimates carry ±15% variance; the exact count is only known after completion.</span>
                                <span>{{ form.prompt.length }}/{{ maxPromptChars }}</span>
                            </div>
                            <InputError class="mt-2" :message="form.errors.prompt" />
                        </div>

                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <InputLabel for="size" value="Size" />
                                <select
                                    id="size"
                                    v-model="form.size"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option v-for="s in sizes" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel for="quality" value="Quality (affects output + reasoning tokens)" />
                                <select
                                    id="quality"
                                    v-model="form.quality"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option v-for="q in qualities" :key="q" :value="q">{{ q }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <InputLabel :value="`Reference images (optional, up to ${maxReferenceImages})`" />
                            <div class="mt-1 flex gap-2">
                                <TextInput
                                    v-model="refUrl"
                                    type="text"
                                    class="block w-full"
                                    placeholder="https://example.com/style-reference.jpg"
                                    @keyup.enter.prevent="addReferenceImage"
                                />
                                <button
                                    type="button"
                                    class="shrink-0 rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-200"
                                    @click="addReferenceImage"
                                >
                                    Add
                                </button>
                            </div>
                            <ul v-if="form.reference_images.length" class="mt-2 space-y-1">
                                <li
                                    v-for="(url, i) in form.reference_images"
                                    :key="i"
                                    class="flex items-center justify-between rounded-md bg-gray-50 px-3 py-1.5 text-sm text-gray-600 ring-1 ring-gray-100"
                                >
                                    <span class="truncate">{{ url }}</span>
                                    <button type="button" class="ml-2 text-red-400 hover:text-red-600" @click="removeReferenceImage(i)">
                                        &times;
                                    </button>
                                </li>
                            </ul>
                            <p class="mt-1 text-xs text-gray-400">
                                Each reference image is counted at a fixed token cost by the provider.
                            </p>
                        </div>

                        <div class="flex items-center justify-end border-t border-gray-100 pt-5">
                            <PrimaryButton :class="{ 'opacity-25': !canEstimate }" :disabled="!canEstimate || form.processing">
                                <span v-if="form.processing">Submitting…</span>
                                <span v-else>Generate image</span>
                            </PrimaryButton>
                        </div>
                    </form>
                </div>

                <!-- Estimate panel -->
                <aside class="mt-6 lg:mt-0 lg:w-1/3">
                    <div class="sticky top-6 space-y-4">
                        <div class="rounded-xl bg-white p-6 shadow ring-1 ring-gray-200/60">
                            <h3 class="font-semibold text-gray-800">
                                Cost estimate
                                <span
                                    v-if="estimating"
                                    class="ml-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-indigo-400 border-t-transparent align-middle"
                                />
                            </h3>

                            <div v-if="estimateError" class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-700 ring-1 ring-amber-200">
                                {{ estimateError }}
                            </div>

                            <div v-else-if="!estimate" class="mt-3 text-sm text-gray-400">
                                Start typing a prompt to see a live estimate before you commit.
                            </div>

                            <div v-else class="mt-4 space-y-3 text-sm">
                                <div class="rounded-lg bg-indigo-50 p-4 ring-1 ring-indigo-100">
                                    <div class="text-xs font-medium uppercase tracking-wide text-indigo-500">
                                        Estimated charge (provider cost &times; {{ estimate.markup }})
                                    </div>
                                    <div class="mt-1 text-2xl font-bold text-indigo-700">
                                        ${{ estimate.charge_min_usd }}
                                        <span class="text-base font-medium text-indigo-400">&ndash;</span>
                                        ${{ estimate.charge_max_usd }}
                                    </div>
                                    <div class="mt-1 text-xs text-indigo-400">
                                        provider cost ${{ estimate.provider_cost_min_usd }} &ndash; ${{ estimate.provider_cost_max_usd }}
                                    </div>
                                </div>

                                <dl class="space-y-1.5 text-gray-600">
                                    <div class="flex justify-between">
                                        <dt>Text tokens (&plusmn;15%)</dt>
                                        <dd>{{ estimate.text_tokens.min }}&ndash;{{ estimate.text_tokens.max }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt>Reference image tokens</dt>
                                        <dd>{{ estimate.image_tokens }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt>Output tokens (exact)</dt>
                                        <dd>{{ estimate.output_tokens }}</dd>
                                    </div>
                                    <div class="flex justify-between">
                                        <dt>Reasoning tokens (estimate)</dt>
                                        <dd>{{ estimate.reasoning_tokens.min }}&ndash;{{ estimate.reasoning_tokens.max }}</dd>
                                    </div>
                                </dl>

                                <p class="border-t border-gray-100 pt-3 text-xs text-gray-400">
                                    At submission we hold the maximum estimate on your balance; after completion
                                    you are charged the <em>actual</em> provider cost &times; {{ estimate.markup }},
                                    and the difference is returned. Failed generations are never charged.
                                </p>
                            </div>
                        </div>

                        <div class="rounded-xl bg-white p-5 text-sm shadow ring-1 ring-gray-200/60">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">Your balance</span>
                                <span class="font-semibold text-gray-800">$ {{ (balance / 1e9).toFixed(6) }}</span>
                            </div>
                            <Link
                                :href="route('credits.purchase')"
                                class="mt-2 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Top up &rarr;
                            </Link>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
