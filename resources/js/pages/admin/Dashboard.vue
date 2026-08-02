<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ScanTable from '@/components/panel/ScanTable.vue';
import type { ScanRow } from '@/components/panel/ScanTable.vue';
import StatCard from '@/components/panel/StatCard.vue';
import { dashboard } from '@/routes';
import { index as adminProducts } from '@/routes/admin/products';

interface UnmatchedRow {
    id: number;
    vendor: string;
    product: string;
    hits: number;
}

defineProps<{
    stats: {
        users: number;
        admins: number;
        hosts: number;
        activeHosts: number;
        vendors: number;
        products: number;
        vulnerabilities: number;
        resolvedRanges: number;
        unmatchedRanges: number;
        unmatchedLookups: number;
    };
    scans: {
        total: number;
        recent: number;
        components: number;
        vulnerable: number;
        unmatched: number;
    };
    lastSyncedAt: string | null;
    topUnmatched: UnmatchedRow[];
    recentScans: ScanRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Admin', href: '/admin' },
        ],
    },
});
</script>

<template>
    <Head title="Admin" />

    <div class="mx-auto w-full max-w-5xl space-y-8 p-4">
        <Heading
            title="Admin"
            description="System-wide state of the vulnerability database and scanner fleet."
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Users"
                :value="stats.users"
                :hint="`${stats.admins} admin`"
            />
            <StatCard
                label="Scan hosts"
                :value="stats.hosts"
                :hint="`${stats.activeHosts} active`"
            />
            <StatCard
                label="CVEs"
                :value="stats.vulnerabilities"
                :hint="`${stats.resolvedRanges} matched ranges`"
            />
            <StatCard
                label="Vulnerable"
                :value="scans.vulnerable"
                :accent="scans.vulnerable > 0"
                hint="reported, last 30 days"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Scans"
                :value="scans.recent"
                :hint="`${scans.total} all time`"
            />
            <StatCard
                label="Components"
                :value="scans.components"
                hint="checked, last 30 days"
            />
            <StatCard
                label="Coverage gaps"
                :value="stats.unmatchedLookups"
                :hint="`${stats.unmatchedRanges} unmatched ranges`"
            />
            <StatCard
                label="Products tracked"
                :value="stats.products"
                :hint="`${stats.vendors} vendors`"
            />
        </div>

        <section class="rounded-lg border border-border p-5">
            <p class="text-xs tracking-wide text-muted-foreground uppercase">
                Last NVD sync
            </p>
            <p class="mt-1 text-lg font-medium">
                {{ lastSyncedAt ?? 'Never synced' }}
            </p>
            <p v-if="!lastSyncedAt" class="mt-1 text-xs text-muted-foreground">
                The scheduler runs nvd:sync hourly. The first run backfills the
                whole catalogue and takes a while.
            </p>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-medium tracking-wide uppercase">
                    Most requested coverage gaps
                </h2>
                <Link
                    :href="adminProducts()"
                    class="text-xs text-muted-foreground transition hover:text-foreground"
                >
                    Manage products →
                </Link>
            </div>

            <div class="rounded-lg border border-border">
                <div
                    v-if="topUnmatched.length === 0"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    Nothing unmatched yet.
                </div>

                <table v-else class="w-full text-sm">
                    <thead class="border-b border-border text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 text-left font-medium">
                                Vendor
                            </th>
                            <th class="px-5 py-3 text-left font-medium">
                                Product
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                Hits
                            </th>
                            <th class="px-5 py-3 text-right font-medium">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in topUnmatched"
                            :key="row.id"
                            class="border-b border-border last:border-0"
                        >
                            <td class="px-5 py-3 font-mono text-xs">
                                {{ row.vendor }}
                            </td>
                            <td class="px-5 py-3 font-mono text-xs">
                                {{ row.product }}
                            </td>
                            <td class="px-5 py-3 text-right font-medium">
                                {{ row.hits }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                <Link
                                    :href="`${adminProducts().url}?vendor=${encodeURIComponent(row.vendor)}&product=${encodeURIComponent(row.product)}`"
                                    class="text-xs text-primary transition hover:underline"
                                >
                                    Add product
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium tracking-wide uppercase">
                Recent scans
            </h2>
            <ScanTable :scans="recentScans" />
        </section>
    </div>
</template>
