<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert, TerminalSquare, Waypoints } from '@lucide/vue';
import { dashboard, login, register, privacyPolicy, termsOfService } from '@/routes';

const features = [
    {
        icon: Waypoints,
        title: 'Continuous NVD sync',
        body: 'CVE and CPE data is pulled from the National Vulnerability Database on a schedule and normalised into version ranges.',
    },
    {
        icon: TerminalSquare,
        title: 'One batched call',
        body: 'Scanners post an entire component inventory in a single request. Matching is two indexed reads, never a query per component.',
    },
    {
        icon: ShieldAlert,
        title: 'Answers, not noise',
        body: 'Exact version matching with inclusive and exclusive bounds, so a patched install is not reported as vulnerable.',
    },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6"
        >
            <span class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="h-4 w-1.5 rounded-full bg-primary" />
                Rozhanitsy
            </span>

            <nav class="flex items-center gap-2 text-sm">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground transition hover:opacity-90"
                >
                    Dashboard
                </Link>

                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-md px-4 py-2 text-muted-foreground transition hover:text-foreground"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground transition hover:opacity-90"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>

        <main class="mx-auto w-full max-w-5xl px-6">
            <section class="border-b border-border py-20 md:py-28">

                <h1
                    class="max-w-3xl text-4xl leading-[1.1] font-semibold tracking-tight text-balance md:text-6xl"
                >
                    Know which of your components are
                    <span class="text-primary">actually vulnerable</span>.
                </h1>

                <p class="mt-6 max-w-2xl text-lg text-muted-foreground">
                    Rozhanitsy ingests the NVD feed and answers one question
                    well: given this inventory of vendors, products and
                    versions, which ones have known CVEs right now?
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    <Link
                        :href="$page.props.auth.user ? dashboard() : register()"
                        class="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground transition hover:opacity-90"
                    >
                        {{
                            $page.props.auth.user
                                ? 'Go to dashboard'
                                : 'Create an account'
                        }}
                    </Link>
                    <Link
                        v-if="!$page.props.auth.user"
                        :href="login()"
                        class="rounded-md border border-border px-5 py-2.5 text-sm font-medium transition hover:bg-accent"
                    >
                        Log in
                    </Link>
                </div>
            </section>

            <section class="grid gap-px border-b border-border md:grid-cols-3">
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="py-10 md:px-6 md:first:pl-0 md:last:pr-0"
                >
                    <component
                        :is="feature.icon"
                        class="mb-4 h-5 w-5 text-primary"
                    />
                    <h2 class="mb-2 font-medium">{{ feature.title }}</h2>
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        {{ feature.body }}
                    </p>
                </article>
            </section>

            <section class="py-16">
                <h2 class="mb-4 text-sm font-medium tracking-wide uppercase">
                    Check an inventory
                </h2>
                <pre
                    class="overflow-x-auto rounded-lg border border-border bg-card p-5 text-xs leading-relaxed text-muted-foreground"
                ><code>curl -X POST https://your-host/api/vulns/check \
  -H "Authorization: Bearer $SCAN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_id": "p1234",
    "components": [
      { "vendor": "automattic", "product": "akismet", "version": "5.3.1" }
    ]
  }'</code></pre>
                <p class="mt-4 text-sm text-muted-foreground">
                    Register to generate a scanner token.
                </p>
            </section>
        </main>

    <footer class="border-t border-border px-6 py-4 text-xs text-muted-foreground bg-background flex justify-between align-center">
      <p>
        Made with sleep deprivation - 🄯2026 - 🄯2026
      </p>
      <p>
        <Link :href="privacyPolicy()" class="hover:underline">Privacy Policy</Link> | <Link :href="termsOfService()" class="hover:underline">Terms of Service</Link>
      </p>
    </footer>
    </div>
</template>
