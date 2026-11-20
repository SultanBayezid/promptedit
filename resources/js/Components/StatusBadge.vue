<script setup>
const props = defineProps({ status: { type: String, required: true } });

const palette = {
    queued: 'bg-gray-100 text-gray-600 ring-gray-200',
    processing: 'bg-amber-50 text-amber-700 ring-amber-200',
    succeeded: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-red-50 text-red-700 ring-red-200',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset"
        :class="palette[status] ?? palette.queued"
    >
        <span
            v-if="status === 'processing' || status === 'queued'"
            class="mr-1.5 h-1.5 w-1.5 animate-pulse rounded-full bg-current"
        />
        {{ status }}
    </span>
</template>
