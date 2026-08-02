<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { KeyRound, LayoutGrid, Package, ShieldCheck, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminProducts } from '@/routes/admin/products';
import { index as adminUsers } from '@/routes/admin/users';
import { index as tokensIndex } from '@/routes/tokens';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'API tokens', href: tokensIndex(), icon: KeyRound },
    ];

    if (page.props.auth.user?.is_admin) {
        items.push(
            { title: 'Admin', href: adminDashboard(), icon: ShieldCheck },
            { title: 'Users', href: adminUsers(), icon: Users },
            { title: 'Products', href: adminProducts(), icon: Package },
        );
    }

    return items;
});

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
