<script setup lang="ts">
defineProps<{
    label: string;
    value: number | string;
    hint?: string;
    accent?: boolean;
}>();
</script>

<template>
    <div class="rounded-lg border border-border p-5">
        <p class="text-xs tracking-wide text-muted-foreground uppercase">
            {{ label }}
        </p>
        <p
            class="mt-1 text-3xl font-semibold"
            :class="accent ? 'text-primary' : ''"
        >
            {{ value }}
        </p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
