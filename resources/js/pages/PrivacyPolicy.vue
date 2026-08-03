<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register, privacyPolicy, termsOfService } from '@/routes';
</script>

<template>
    <Head title="Privacy Policy" />

    <div class="min-h-screen bg-background text-foreground">
        <header class="mx-auto flex w-full max-w-3xl items-center justify-between px-6 py-6">
            <Link :href="'/'" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="h-4 w-1.5 rounded-full bg-primary" />
                Rozhanitsy
            </Link>

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

        <main class="mx-auto w-full max-w-3xl px-6 py-12">
            <h1 class="text-3xl font-semibold tracking-tight">Privacy Policy</h1>
            <p class="mt-3 text-sm text-muted-foreground">
                <strong class="font-medium text-foreground">Service:</strong> Rozhanitsy<br />
                <strong class="font-medium text-foreground">Last updated:</strong> 3 August 2026
            </p>

            <p class="mt-6 text-sm leading-relaxed text-muted-foreground">
                This Privacy Policy explains how Tadeáš Ditte ("we," "us," "our") collects, uses, and
                protects information in connection with the hosted instance of Rozhanitsy we operate
                (the "Service"). It applies to visitors, registered users, and hosts that connect via
                API token (e.g., through the Svetovid scanner).
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Rozhanitsy is free and open-source software, licensed under AGPL-3.0 and available at
                <a
                    href="https://github.com/TadeasDitte/Rozhanitsy"
                    class="text-primary hover:underline"
                    target="_blank"
                    rel="noopener noreferrer"
                    >github.com/TadeasDitte/Rozhanitsy</a
                >. This Policy covers only
                <strong class="font-medium text-foreground">our</strong> hosted instance. If you or
                someone else runs their own instance from the public source code, that operator is
                the data controller for that instance and is responsible for its own privacy policy —
                this Policy does not apply to data you send to a self-hosted instance we do not
                operate. See our
                <Link :href="termsOfService()" class="text-primary hover:underline">Terms of Service</Link>
                for details on the open-source license.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                1. Who We Are
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Rozhanitsy is a vulnerability-intelligence API that aggregates CVE/CPE data from
                public and licensed sources (NIST National Vulnerability Database) and exposes a version-matching API so that
                connected hosts can check installed software for known vulnerabilities.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Data controller: Tadeáš Ditte, operating this hosted instance of Rozhanitsy as an
                individual (sole operator), based in Slovakia.<br />
                Contact: <a href="mailto:contact@tadesrv.eu" class="text-primary hover:underline">contact@tadesrv.eu</a><br />
                Abuse reports: <a href="mailto:abuse@tadesrv.eu" class="text-primary hover:underline">abuse@tadesrv.eu</a>
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                2. Data We Collect
            </h2>

            <h3 class="mt-6 font-medium">2.1 Account data</h3>
            <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                If you register for the Service, we collect:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>
                    A <strong class="font-medium text-foreground">name</strong> field — this is a
                    display name/username, not necessarily your legal name, and you may use a
                    pseudonym
                </li>
                <li>
                    Either a <strong class="font-medium text-foreground">password</strong> (stored
                    hashed, never in plain text) or, if you sign in via OAuth, an identifier from your
                    OAuth provider. If you use OAuth, your provider may share additional information
                    with us (such as an email address) depending on your settings with that provider
                    and the scopes we request
                </li>
                <li>
                    <strong class="font-medium text-foreground">Two-factor authentication (2FA)
                    data</strong>, if enabled — we store a 2FA secret and/or one-time backup/recovery
                    passcodes (hashed where applicable) used to verify your identity at login. We do
                    not have access to your authenticator app itself
                </li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We do require an email address directly as part of account
                registration for account recovery, either you provide it at registration or it may be
                passed to us by an OAuth provider.
            </p>

            <h3 class="mt-6 font-medium">2.2 API / host data</h3>
            <p class="mt-2 text-sm leading-relaxed text-muted-foreground">
                When you or a connected scanner (such as Svetovid) use the API, we collect:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>An authentication token (Laravel Sanctum) tied to your registered host</li>
                <li>
                    The IP address and basic request metadata (timestamp, endpoint, rate-limit
                    counters) of each API call
                </li>
                <li>
                    Software inventory data submitted for vulnerability checks — specifically:
                    detected CMS platform names, plugin names, and version strings for the directory
                    being scanned.
                    <strong class="font-medium text-foreground">
                        We do not collect file contents, customer PII stored on scanned sites,
                        database contents, or credentials from scanned systems.
                    </strong>
                    The scanner is designed to submit only software identity and version metadata,
                    batched per customer directory.
                </li>
            </ul>

            <h3 class="mt-6 font-medium">2.3 Automatically generated data</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>
                    Match results we return to you (which CVEs matched, confidence level, unmatched
                    entries)
                </li>
                <li>
                    Aggregated, anonymized statistics about scan volume and unmatched software, which
                    we may use to prioritize expanding our vulnerability data coverage
                </li>
            </ul>

            <h3 class="mt-6 font-medium">2.4 We do not intentionally collect</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>Special categories of personal data (health, biometric, etc.)</li>
                <li>Content of files hosted on scanned servers</li>
                <li>Credentials, session tokens, or secrets found on scanned systems</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                If you believe such data has been inadvertently transmitted to us, contact us at
                <a href="mailto:contact@tadesrv.eu" class="text-primary hover:underline">contact@tadesrv.eu</a>
                and we will delete it.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                3. How We Use Data
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We use the data described above to:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>Authenticate API requests and enforce per-host rate limits</li>
                <li>
                    Match submitted software/version data against our vulnerability dataset and
                    return results
                </li>
                <li>
                    Identify coverage gaps (via unmatched results) to prioritize which
                    products/vendors we ingest data for next
                </li>
                <li>Maintain, secure, and improve the Service (e.g., debugging, abuse prevention)</li>
                <li>
                    Communicate with you about your account, service changes, or security notices
                </li>
                <li>Comply with legal obligations</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We do <strong class="font-medium text-foreground">not</strong> sell your data. We do
                not use software inventory data submitted via the API for advertising purposes.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                4. Legal Basis for Processing (GDPR)
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Where the GDPR applies, we process personal data on the following bases:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li><strong class="font-medium text-foreground">Contract</strong> — to provide the Service you signed up for</li>
                <li><strong class="font-medium text-foreground">Legitimate interest</strong> — to secure the Service, prevent abuse, and improve vulnerability coverage</li>
                <li><strong class="font-medium text-foreground">Consent</strong> — where required, e.g., for optional communications</li>
                <li><strong class="font-medium text-foreground">Legal obligation</strong> — where processing is required by law</li>
            </ul>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                5. Data Sharing
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We share data only in the following circumstances:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>
                    <strong class="font-medium text-foreground">Third-party vulnerability sources</strong>:
                    We do not send your scanned software inventory data <em>to</em> NVD, those sources are inbound data feeds into our system, not recipients
                    of your data.
                </li>
                <li>
                    <strong class="font-medium text-foreground">Service providers</strong>:
                    Infrastructure providers (e.g., hosting, database, error-monitoring) who process
                    data on our behalf under appropriate confidentiality obligations.
                </li>
                <li>
                    <strong class="font-medium text-foreground">Legal requirements</strong>: If
                    required to comply with a legal obligation, court order, or to protect the
                    rights, safety, or property of us or others.
                </li>
                <li>
                    <strong class="font-medium text-foreground">Business transfers</strong>: If the
                    Service is acquired or merged, data may transfer as part of that transaction,
                    subject to this Policy or a policy offering at least equivalent protection.
                </li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We do not share raw host-level scan data with other customers. Aggregated/anonymized
                statistics (e.g., "X% of scanned WordPress sites run a vulnerable plugin version") may
                be published or shared without identifying individual hosts.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                6. Data Retention
            </h2>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>
                    <strong class="font-medium text-foreground">Account data</strong>: retained for as
                    long as your account is active, plus 30 days after deletion for backup/legal
                    purposes.
                </li>
                <li>
                    <strong class="font-medium text-foreground">API request logs</strong>: retained
                    for 90 days for security and abuse-prevention purposes, then deleted or
                    anonymized.
                </li>
                <li>
                    <strong class="font-medium text-foreground">Software inventory / scan results</strong>:
                    retained for 12 months to support historical trend analysis and re-matching
                    against newly ingested CVEs, then anonymized or deleted. You may request earlier
                    deletion.
                </li>
                <li>
                    <strong class="font-medium text-foreground">Vulnerability dataset itself</strong>
                    (CVE/CPE records from NVD etc.) is not personal data and is retained indefinitely
                    as core service data.
                </li>
            </ul>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                7. Your Rights
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Depending on your jurisdiction (including under GDPR if you are in the EU/EEA/UK),
                you may have the right to:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>Access the personal data we hold about you</li>
                <li>Correct inaccurate data</li>
                <li>Request deletion of your data ("right to be forgotten")</li>
                <li>Object to or restrict certain processing</li>
                <li>Request data portability</li>
                <li>Withdraw consent where processing is based on consent</li>
                <li>Lodge a complaint with your local data protection authority</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                To exercise these rights, contact us at
                <a href="mailto:contact@tadesrv.eu" class="text-primary hover:underline">contact@tadesrv.eu</a>.
                We will respond within the timeframe required by applicable law.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                8. Security
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We use industry-standard measures to protect data in transit and at rest, including:
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground">
                <li>Token-based authentication (Laravel Sanctum) rather than shared credentials</li>
                <li>
                    Passwords stored using one-way hashing; 2FA secrets and backup passcodes stored
                    encrypted/hashed, never in plain text
                </li>
                <li>Rate limiting/throttling on API endpoints</li>
                <li>Encrypted connections (HTTPS/TLS) for all API traffic</li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                No system is completely secure, and we cannot guarantee absolute security. If we
                become aware of a breach affecting your data, we will notify you as required by
                applicable law.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                9. International Transfers
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                If we transfer data outside your country of residence (e.g., to infrastructure
                providers located elsewhere), we take steps to ensure appropriate safeguards are in
                place, such as standard contractual clauses where required.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                10. Children's Privacy
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                The Service is not directed at individuals under 18. We do not knowingly collect
                personal data from children.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                11. Cookies
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We use essential cookies for authentication/session management on our web dashboard.
                We do not use third-party advertising or tracking cookies.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                12. Changes to This Policy
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We may update this Privacy Policy from time to time. Material changes will be posted
                with an updated "Last updated" date, and where required, we will notify you directly.
            </p>

            <h2 class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight">
                13. Contact
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                For privacy questions or to exercise your rights, contact us at
                <a href="mailto:contact@tadesrv.eu" class="text-primary hover:underline">contact@tadesrv.eu</a>.
            </p>
        </main>

        <footer
            class="align-center flex justify-between border-t border-border bg-background px-6 py-4 text-xs text-muted-foreground"
        >
            <p>Made with sleep deprivation - 🄯2026 - 🄯2026</p>
            <p>
                <Link :href="privacyPolicy()" class="hover:underline">Privacy Policy</Link> |
                <Link :href="termsOfService()" class="hover:underline">Terms of Service</Link>
            </p>
        </footer>
    </div>
</template>
