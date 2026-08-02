<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ShieldCheck, ShieldMinus, ShieldPlus, Trash2 } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { revoke as revokeToken } from '@/routes/admin/scan-hosts';
import { demote, promote } from '@/routes/admin/users';

interface AdminHost {
    id: number;
    hostname: string;
    is_active: boolean;
    has_token: boolean;
    last_seen_at: string | null;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    created_at: string | null;
    hosts: AdminHost[];
}

const props = defineProps<{
    users: AdminUser[];
    adminCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Users', href: '/admin/users' },
        ],
    },
});

const page = usePage();

function isSelf(user: AdminUser): boolean {
    return page.props.auth.user?.id === user.id;
}

function canDemote(user: AdminUser): boolean {
    return user.is_admin && !isSelf(user) && props.adminCount > 1;
}

function toggleAdmin(user: AdminUser) {
    if (user.is_admin) {
        router.delete(demote(user.id).url, { preserveScroll: true });

        return;
    }

    router.post(promote(user.id).url, {}, { preserveScroll: true });
}

function revoke(host: AdminHost) {
    if (confirm(`Revoke the token for ${host.hostname}?`)) {
        router.delete(revokeToken(host.id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Users" />

    <div class="mx-auto w-full max-w-5xl space-y-8 p-4">
        <Heading
            title="Users"
            description="Every registered account, the scan hosts it owns, and who can administer the panel."
        />

        <p
            v-if="page.props.errors.is_admin"
            class="rounded-lg border border-primary/40 bg-primary/5 px-5 py-3 text-sm"
        >
            {{ page.props.errors.is_admin }}
        </p>

        <div class="space-y-4">
            <article
                v-for="user in users"
                :key="user.id"
                class="rounded-lg border border-border"
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4"
                >
                    <div>
                        <p class="flex items-center gap-2 font-medium">
                            {{ user.name }}
                            <ShieldCheck
                                v-if="user.is_admin"
                                class="h-4 w-4 text-primary"
                            />
                            <span
                                v-if="isSelf(user)"
                                class="text-xs text-muted-foreground"
                            >
                                you
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ user.email }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3">
                        <p class="text-right text-xs text-muted-foreground">
                            Joined {{ user.created_at }}
                        </p>

                        <Button
                            v-if="!user.is_admin"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="toggleAdmin(user)"
                        >
                            <ShieldPlus class="h-3.5 w-3.5" />
                            Make admin
                        </Button>
                        <Button
                            v-else-if="canDemote(user)"
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="toggleAdmin(user)"
                        >
                            <ShieldMinus class="h-3.5 w-3.5" />
                            Remove admin
                        </Button>
                    </div>
                </header>

                <div
                    v-if="user.hosts.length === 0"
                    class="px-5 py-4 text-sm text-muted-foreground"
                >
                    No scan hosts.
                </div>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="host in user.hosts"
                        :key="host.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                    >
                        <div>
                            <p class="font-mono text-xs">{{ host.hostname }}</p>
                            <p class="text-xs text-muted-foreground">
                                Last seen {{ host.last_seen_at ?? 'never' }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
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
                    </li>
                </ul>
            </article>
        </div>
    </div>
</template>
