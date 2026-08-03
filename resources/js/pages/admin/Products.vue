<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { onMounted } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { destroy, store } from '@/routes/admin/products';

interface ProductRow {
    id: number;
    name: string;
    type: string;
}

interface VendorRow {
    id: number;
    name: string;
    products: ProductRow[];
}

defineProps<{
    vendors: VendorRow[];
    types: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Products', href: '/admin/products' },
        ],
    },
});

const form = useForm({
    vendor_name: '',
    product_name: '',
    type: 'plugin',
});

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const vendor = params.get('vendor');
    const product = params.get('product');

    if (vendor) {
        form.vendor_name = vendor;
    }

    if (product) {
        form.product_name = product;
    }
});

function submit() {
    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => form.reset('vendor_name', 'product_name'),
    });
}

function remove(product: ProductRow) {
    if (
        confirm(
            `Remove ${product.name}? This also drops any CVE matches for it.`,
        )
    ) {
        router.delete(destroy(product.id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Products" />

    <div class="mx-auto w-full max-w-5xl space-y-8 p-4">
        <Heading
            title="Products"
            description="The vendor/product catalog scans are matched against. Anything not listed here always comes back unmatched, no matter how much CVE data is synced."
        />

        <form
            class="grid gap-3 rounded-lg border border-border p-5 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end"
            @submit.prevent="submit"
        >
            <div class="space-y-2">
                <Label for="vendor_name">Vendor</Label>
                <Input
                    id="vendor_name"
                    v-model="form.vendor_name"
                    placeholder="wordpress"
                    autocomplete="off"
                />
                <InputError :message="form.errors.vendor_name" />
            </div>
            <div class="space-y-2">
                <Label for="product_name">Product</Label>
                <Input
                    id="product_name"
                    v-model="form.product_name"
                    placeholder="wordpress"
                    autocomplete="off"
                />
                <InputError :message="form.errors.product_name" />
            </div>
            <div class="space-y-2">
                <Label for="type">Type</Label>
                <Select v-model="form.type">
                    <SelectTrigger id="type">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="type in types"
                            :key="type"
                            :value="type"
                        >
                            {{ type }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.type" />
            </div>
            <Button type="submit" :disabled="form.processing"
                >Add product</Button
            >
        </form>

        <div class="space-y-4">
            <div
                v-if="vendors.length === 0"
                class="rounded-lg border border-border p-8 text-center text-sm text-muted-foreground"
            >
                No products tracked yet.
            </div>

            <article
                v-for="vendor in vendors"
                :key="vendor.id"
                class="rounded-lg border border-border"
            >
                <header class="border-b border-border px-5 py-4 font-medium">
                    {{ vendor.name }}
                </header>

                <div
                    v-if="vendor.products.length === 0"
                    class="px-5 py-4 text-sm text-muted-foreground"
                >
                    No products for this vendor.
                </div>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="product in vendor.products"
                        :key="product.id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <div>
                            <p class="text-sm">{{ product.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ product.type }}
                            </p>
                        </div>

                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            @click="remove(product)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                            Remove
                        </Button>
                    </li>
                </ul>
            </article>
        </div>
    </div>
</template>
