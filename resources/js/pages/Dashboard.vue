<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ScanTable from '@/components/panel/ScanTable.vue';
import type { ScanRow } from '@/components/panel/ScanTable.vue';
import StatCard from '@/components/panel/StatCard.vue';
import { dashboard } from '@/routes';
import { index as tokensIndex } from '@/routes/tokens';

interface HostRow {
    id: number;
    hostname: string;
    is_active: boolean;
    has_token: boolean;
    last_seen_at: string | null;
}

defineProps<{
    stats: {
        hosts: number;
        activeHosts: number;
        scans: number;
        components: number;
        vulnerable: number;
        unmatched: number;
    };
    hosts: HostRow[];
    recentScans: ScanRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto w-full max-w-5xl space-y-8 p-4">
        <Heading
            title="Dashboard"
            description="Scanner activity across your hosts over the last 30 days."
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Scan hosts"
                :value="stats.hosts"
                :hint="`${stats.activeHosts} active`"
            />
            <StatCard label="Scans" :value="stats.scans" hint="last 30 days" />
            <StatCard
                label="Components"
                :value="stats.components"
                hint="checked"
            />
            <StatCard
                label="Vulnerable"
                :value="stats.vulnerable"
                :accent="stats.vulnerable > 0"
                :hint="`${stats.unmatched} unmatched`"
            />
        </div>

        <section
            v-if="hosts.length === 0"
            class="rounded-lg border border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                You have no scan hosts yet.
            </p>
            <Link
                :href="tokensIndex()"
                class="mt-4 inline-block rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition hover:opacity-90"
            >
                Generate a token
            </Link>
        </section>

        <section v-else class="space-y-3">
            <h2 class="text-sm font-medium tracking-wide uppercase">Hosts</h2>
            <ul class="divide-y divide-border rounded-lg border border-border">
                <li
                    v-for="host in hosts"
                    :key="host.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                >
                    <div>
                        <p class="font-mono text-xs">{{ host.hostname }}</p>
                        <p class="text-xs text-muted-foreground">
                            Last seen {{ host.last_seen_at ?? 'never' }}
                        </p>
                    </div>
                    <span
                        v-if="host.is_active && host.has_token"
                        class="inline-flex items-center gap-1.5 text-xs"
                    >
                        <span class="h-1.5 w-1.5 rounded-full bg-primary" />
                        Active
                    </span>
                    <span v-else class="text-xs text-muted-foreground">
                        Revoked
                    </span>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium tracking-wide uppercase">
                Recent scans
            </h2>
            <ScanTable :scans="recentScans" />
        </section>
    </div>
</template>
