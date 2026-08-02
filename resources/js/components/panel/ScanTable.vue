<script setup lang="ts">
export interface ScanRow {
    id: number;
    hostname: string | null;
    tenant_id: string | null;
    components: number;
    vulnerable: number;
    unmatched: number;
    scanned_at: string | null;
}

defineProps<{ scans: ScanRow[] }>();
</script>

<template>
    <div class="rounded-lg border border-border">
        <div
            v-if="scans.length === 0"
            class="p-8 text-center text-sm text-muted-foreground"
        >
            No scans recorded yet.
        </div>

        <table v-else class="w-full text-sm">
            <thead class="border-b border-border text-muted-foreground">
                <tr>
                    <th class="px-5 py-3 text-left font-medium">Host</th>
                    <th class="px-5 py-3 text-left font-medium">Tenant</th>
                    <th class="px-5 py-3 text-right font-medium">Components</th>
                    <th class="px-5 py-3 text-right font-medium">Vulnerable</th>
                    <th class="px-5 py-3 text-right font-medium">Unmatched</th>
                    <th class="px-5 py-3 text-left font-medium">When</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="scan in scans"
                    :key="scan.id"
                    class="border-b border-border last:border-0"
                >
                    <td class="px-5 py-3 font-mono text-xs">
                        {{ scan.hostname ?? '—' }}
                    </td>
                    <td
                        class="px-5 py-3 font-mono text-xs text-muted-foreground"
                    >
                        {{ scan.tenant_id ?? 'standalone' }}
                    </td>
                    <td class="px-5 py-3 text-right">{{ scan.components }}</td>
                    <td
                        class="px-5 py-3 text-right"
                        :class="
                            scan.vulnerable > 0
                                ? 'font-medium text-primary'
                                : ''
                        "
                    >
                        {{ scan.vulnerable }}
                    </td>
                    <td class="px-5 py-3 text-right text-muted-foreground">
                        {{ scan.unmatched }}
                    </td>
                    <td class="px-5 py-3 text-xs text-muted-foreground">
                        {{ scan.scanned_at }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
