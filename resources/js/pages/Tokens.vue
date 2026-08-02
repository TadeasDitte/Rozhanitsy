<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, RotateCw, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { destroy, regenerate, store } from '@/routes/tokens';

interface ScanHostRow {
    id: number;
    hostname: string;
    is_active: boolean;
    has_token: boolean;
    last_seen_at: string | null;
    created_at: string | null;
}

defineProps<{ hosts: ScanHostRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'API tokens', href: '/tokens' },
        ],
    },
});

const page = usePage();
const copied = ref(false);

const issued = computed(
    () =>
        (
            page.props.flash as {
                scanToken?: { hostname: string; token: string };
            }
        )?.scanToken,
);

const form = useForm({ hostname: '' });

function submit() {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('hostname'),
    });
}

function copy(token: string) {
    navigator.clipboard.writeText(token);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

function rotate(host: ScanHostRow) {
    router.post(regenerate(host.id).url, {}, { preserveScroll: true });
}

function revoke(host: ScanHostRow) {
    if (confirm(`Revoke the token for ${host.hostname}?`)) {
        router.delete(destroy(host.id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="API tokens" />

    <div class="mx-auto w-full max-w-4xl space-y-8 p-4">
        <Heading
            title="API tokens"
            description="Each scan host authenticates to the scanner API with its own bearer token."
        />

        <div
            v-if="issued"
            class="rounded-lg border border-primary/40 bg-primary/5 p-5"
        >
            <p class="mb-1 flex items-center gap-2 text-sm font-medium">
                <KeyRound class="h-4 w-4 text-primary" />
                Token for {{ issued.hostname }}
            </p>
            <p class="mb-3 text-sm text-muted-foreground">
                Copy it now — it cannot be shown again.
            </p>

            <div class="flex items-center gap-2">
                <code
                    class="flex-1 overflow-x-auto rounded-md border border-border bg-background px-3 py-2 font-mono text-xs"
                    >{{ issued.token }}</code
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="copy(issued.token)"
                >
                    <Check v-if="copied" class="h-4 w-4" />
                    <Copy v-else class="h-4 w-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </div>

        <form
            class="flex flex-col gap-3 rounded-lg border border-border p-5 sm:flex-row sm:items-end"
            @submit.prevent="submit"
        >
            <div class="flex-1 space-y-2">
                <Label for="hostname">New scan host</Label>
                <Input
                    id="hostname"
                    v-model="form.hostname"
                    placeholder="scanner-01.example.com"
                    autocomplete="off"
                />
                <InputError :message="form.errors.hostname" />
            </div>
            <Button type="submit" :disabled="form.processing">
                Generate token
            </Button>
        </form>

        <div class="rounded-lg border border-border">
            <div
                v-if="hosts.length === 0"
                class="p-8 text-center text-sm text-muted-foreground"
            >
                No scan hosts yet.
            </div>

            <table v-else class="w-full text-sm">
                <thead class="border-b border-border text-muted-foreground">
                    <tr>
                        <th class="px-5 py-3 text-left font-medium">Host</th>
                        <th class="px-5 py-3 text-left font-medium">Status</th>
                        <th class="px-5 py-3 text-left font-medium">
                            Last seen
                        </th>
                        <th class="px-5 py-3 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="host in hosts"
                        :key="host.id"
                        class="border-b border-border last:border-0"
                    >
                        <td class="px-5 py-3 font-mono text-xs">
                            {{ host.hostname }}
                        </td>
                        <td class="px-5 py-3">
                            <span
                                v-if="host.is_active && host.has_token"
                                class="inline-flex items-center gap-1.5 text-xs"
                            >
                                <span
                                    class="h-1.5 w-1.5 rounded-full bg-primary"
                                />
                                Active
                            </span>
                            <span v-else class="text-xs text-muted-foreground">
                                Revoked
                            </span>
                        </td>
                        <td class="px-5 py-3 text-xs text-muted-foreground">
                            {{ host.last_seen_at ?? 'Never' }}
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="rotate(host)"
                                >
                                    <RotateCw class="h-3.5 w-3.5" />
                                    Regenerate
                                </Button>
                                <Button
                                    v-if="host.has_token"
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    @click="revoke(host)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                    Revoke
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
