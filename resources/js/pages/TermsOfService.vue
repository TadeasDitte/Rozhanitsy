<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    dashboard,
    login,
    register,
    privacyPolicy,
    termsOfService,
} from '@/routes';
</script>

<template>
    <Head title="Terms of Service" />

    <div class="min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-3xl items-center justify-between px-6 py-6"
        >
            <Link
                :href="'/'"
                class="flex items-center gap-2 font-semibold tracking-tight"
            >
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
            <h1 class="text-3xl font-semibold tracking-tight">
                Terms of Service
            </h1>
            <p class="mt-3 text-sm text-muted-foreground">
                <strong class="font-medium text-foreground">Service:</strong>
                Rozhanitsy<br />
                <strong class="font-medium text-foreground"
                    >Last updated:</strong
                >
                3 August 2026
            </p>

            <p class="mt-6 text-sm leading-relaxed text-muted-foreground">
                These Terms of Use ("Terms") govern your access to and use of
                the hosted instance of Rozhanitsy operated by Tadeáš Ditte
                ("we," "us," "our") (the "Service"), a
                vulnerability-intelligence API that ingests CVE/CPE data from
                sources including the NIST National Vulnerability Database (NVD)
                and, where applicable, WPScan and Patchstack, and exposes it via
                an API for the purpose of identifying known vulnerabilities in
                installed software (e.g., CMS platforms and plugins).
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Rozhanitsy is free and open-source software. These Terms govern
                only your use of
                <strong class="font-medium text-foreground">our</strong> hosted
                instance of the Service — see
                <strong class="font-medium text-foreground"
                    >§2 (Open Source License)</strong
                >
                for how the underlying software is licensed and what applies if
                you run your own instance.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                By registering for an account, obtaining an API token, or
                otherwise using our hosted Service, you agree to these Terms. If
                you do not agree, do not use the Service.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                1. Eligibility
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                You must be at least 18 years old, or the age of legal majority
                in your jurisdiction, and have the authority to enter into these
                Terms on behalf of yourself or the organization you represent.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                2. Open Source License
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                The source code of Rozhanitsy is licensed under the
                <strong class="font-medium text-foreground"
                    >GNU Affero General Public License v3.0 (AGPL-3.0)</strong
                >
                and is publicly available at
                <a
                    href="https://github.com/TadeasDitte/Rozhanitsy"
                    class="text-primary hover:underline"
                    target="_blank"
                    rel="noopener noreferrer"
                    >github.com/TadeasDitte/Rozhanitsy</a
                >. You are free to inspect, run, modify, and redistribute the
                code under the terms of that license.
            </p>
            <ul
                class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground"
            >
                <li>
                    If you self-host a copy or a modified version of the Service
                    and make it available to other users over a network,
                    AGPL-3.0 §13 requires that you also make the corresponding
                    source code available to those users. These Terms of Service
                    do
                    <strong class="font-medium text-foreground">not</strong>
                    apply to your self-hosted instance — only the license text
                    governs your use of the code, and you are solely responsible
                    for your own deployment, including its own privacy policy,
                    terms, and legal compliance.
                </li>
                <li>
                    The AGPL-3.0 license covers the Service's source code. It
                    does
                    <strong class="font-medium text-foreground">not</strong>
                    extend to the "Rozhanitsy" name or branding, nor to
                    third-party vulnerability data we aggregate under separate
                    licenses or terms of use (e.g., NVD, WPScan, Patchstack) —
                    see §4 and §9.
                </li>
                <li>
                    As permitted by AGPL-3.0 §7, the software is provided with
                    <strong class="font-medium text-foreground"
                        >absolutely no warranty</strong
                    >, as stated in §6 of these Terms and in the license itself.
                </li>
            </ul>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                3. Accounts and API Access
            </h2>
            <ul
                class="mt-3 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground"
            >
                <li>
                    You must register for an account to obtain an API token (via
                    Laravel Sanctum) for programmatic access to our hosted
                    instance.
                </li>
                <li>
                    You are responsible for keeping your API token confidential.
                    You are responsible for all activity that occurs under your
                    token, whether authorized by you or not.
                </li>
                <li>
                    You must notify us promptly if you suspect your token has
                    been compromised. We may revoke or rotate tokens at our
                    discretion, including in response to suspected abuse.
                </li>
                <li>
                    You agree to provide accurate registration information and
                    keep it up to date. Your account "name" field is a display
                    name and need not be your legal name.
                </li>
                <li>
                    If you enable two-factor authentication (2FA), you are
                    responsible for securely storing your backup/recovery
                    passcodes. We cannot restore access to an account if both
                    your 2FA method and your recovery passcodes are lost.
                </li>
            </ul>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                4. Acceptable Use
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                When using our hosted Service, you agree not to:
            </p>
            <ul
                class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground"
            >
                <li>
                    Use the Service to scan, probe, or submit data about systems
                    you do not own or do not have explicit authorization to
                    assess.
                </li>
                <li>
                    Exceed rate limits, attempt to circumvent throttling or
                    authentication controls, or otherwise interfere with the
                    hosted Service's normal operation.
                </li>
                <li>
                    Use the Service to develop, test, or deploy exploits,
                    malware, or other tools intended to cause unauthorized harm
                    to systems.
                </li>
                <li>
                    Bulk-extract, scrape, or redistribute vulnerability data
                    obtained through the Service where that data originates from
                    a third-party source (e.g., WPScan, Patchstack) licensed to
                    us for use within the Service, in a manner that would
                    violate our license terms with that source. This restriction
                    applies to third-party data only — it does not restrict
                    anything AGPL-3.0 already permits you to do with our own
                    source code.
                </li>
                <li>
                    Resell or sublicense access to our hosted API without our
                    prior written consent.
                </li>
                <li>
                    Submit false, misleading, or malicious data through the API.
                </li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We may suspend or terminate access for violations of this
                section without prior notice. To report suspected abuse of the
                Service, contact
                <a
                    href="mailto:abuse@tadesrv.eu"
                    class="text-primary hover:underline"
                    >abuse@tadesrv.eu</a
                >.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                5. Nature of the Data — No Warranty of Completeness
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                The Service aggregates vulnerability data from third-party
                sources (including NVD, WPScan, and Patchstack) and applies
                automated matching logic (including fuzzy matching via our
                <code
                    class="rounded border border-border bg-card px-1 py-0.5 font-mono text-xs"
                    >cpe_map</code
                >
                translation layer) to associate known CVEs with software
                versions.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                <strong class="font-medium text-foreground"
                    >You acknowledge and agree that:</strong
                >
            </p>
            <ul
                class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground"
            >
                <li>
                    Vulnerability coverage is inherently incomplete. Some
                    software, versions, or vulnerabilities may not be present in
                    the dataset, particularly for CMS plugins where upstream
                    source coverage is inconsistent.
                </li>
                <li>
                    Matches are produced by automated, best-effort logic and may
                    include false positives, false negatives, or entries of
                    unconfirmed match confidence (reflected in fields such as
                    <code
                        class="rounded border border-border bg-card px-1 py-0.5 font-mono text-xs"
                        >exact</code
                    >,
                    <code
                        class="rounded border border-border bg-card px-1 py-0.5 font-mono text-xs"
                        >fuzzy</code
                    >, or
                    <code
                        class="rounded border border-border bg-card px-1 py-0.5 font-mono text-xs"
                        >unmatched</code
                    >).
                </li>
                <li>
                    An "unmatched" or absent result for a given piece of
                    software does
                    <strong class="font-medium text-foreground">not</strong>
                    mean that software is free of known vulnerabilities — it may
                    simply reflect a gap in our current data ingestion.
                </li>
                <li>
                    The Service is a decision-support tool, not a substitute for
                    independent security review, penetration testing, or a full
                    vulnerability management program. Any decision you make
                    based on the Service's output — including a decision to
                    <strong class="font-medium text-foreground">not</strong>
                    patch, update, or further investigate a piece of software —
                    is made at your own discretion and risk.
                </li>
            </ul>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                6. No Warranty
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE," WITHOUT
                WARRANTIES OF ANY KIND, WHETHER EXPRESS, IMPLIED, OR STATUTORY,
                INCLUDING WITHOUT LIMITATION WARRANTIES OF MERCHANTABILITY,
                FITNESS FOR A PARTICULAR PURPOSE, NON-INFRINGEMENT, ACCURACY, OR
                COMPLETENESS, CONSISTENT WITH SECTIONS 15 AND 16 OF THE AGPL-3.0
                LICENSE GOVERNING THE UNDERLYING SOFTWARE. WE DO NOT WARRANT
                THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR THAT
                VULNERABILITY DATA WILL BE COMPLETE OR CURRENT AT ANY GIVEN
                TIME.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                7. Limitation of Liability
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                TO THE MAXIMUM EXTENT PERMITTED BY LAW, TADEÁŠ DITTE SHALL NOT
                BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL,
                OR PUNITIVE DAMAGES, OR ANY LOSS OF DATA, REVENUE, OR PROFITS,
                ARISING FROM OR RELATED TO YOUR USE OF THE SERVICE OR THE
                UNDERLYING OPEN-SOURCE SOFTWARE, INCLUDING ANY RELIANCE ON
                VULNERABILITY DATA RETURNED BY THE API OR ANY ACTION (OR
                INACTION) YOU TAKE AS A RESULT OF IT, EVEN IF ADVISED OF THE
                POSSIBILITY OF SUCH DAMAGES. OUR TOTAL LIABILITY FOR ANY CLAIM
                ARISING OUT OF THESE TERMS SHALL NOT EXCEED THE GREATER OF (A)
                THE AMOUNT YOU PAID US IN THE 12 MONTHS PRECEDING THE CLAIM, OR
                (B) €50.
            </p>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Some jurisdictions do not allow the exclusion or limitation of
                certain damages, so some of the above limitations may not apply
                to you.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                8. Indemnification and Your Responsibility
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                You agree to defend, indemnify, and hold harmless Tadeáš Ditte
                from any claim, liability, damage, or expense (including
                reasonable legal fees) arising out of:
            </p>
            <ul
                class="mt-2 list-disc space-y-1 pl-5 text-sm leading-relaxed text-muted-foreground"
            >
                <li>
                    Your use of the Service to scan, assess, or submit data
                    about systems you were not authorized to assess;
                </li>
                <li>
                    Your violation of these Terms or of any applicable law in
                    connection with your use of the Service; or
                </li>
                <li>
                    Any decision made, or action taken, in reliance on the
                    Service's output without independent verification.
                </li>
            </ul>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                You are solely responsible for verifying that your use of the
                Service, and of any data it returns, complies with the law and
                with any obligations you owe to third parties.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                9. Data You Submit
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                If you use the Service in conjunction with the Svetovid scanner
                or otherwise submit data about software installed on hosts you
                manage (e.g., CMS type, plugin names, version strings), you
                represent that you are authorized to collect and transmit that
                data. See our
                <Link
                    :href="privacyPolicy()"
                    class="text-primary hover:underline"
                    >Privacy Policy</Link
                >
                for how we handle data submitted to the Service.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                10. Intellectual Property
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                The Service's source code is licensed under AGPL-3.0 as
                described in §2. The "Rozhanitsy" name and any associated
                branding are not licensed under AGPL-3.0 and remain the property
                of Tadeáš Ditte. Underlying vulnerability data (e.g., from NVD)
                remains subject to the terms of its original source; we make no
                independent ownership claim over third-party CVE data itself.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                11. Termination
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We may suspend or terminate your access to our hosted Service at
                any time, with or without cause, with or without notice. You may
                stop using the Service and request account deletion at any time
                by contacting us at
                <a
                    href="mailto:contact@tadesrv.eu"
                    class="text-primary hover:underline"
                    >contact@tadesrv.eu</a
                >. Termination of your account does not affect any rights you
                have under AGPL-3.0 to the source code itself.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                12. Changes to the Service or These Terms
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                We may modify the hosted Service or these Terms at any time.
                Material changes will be posted with an updated "Last updated"
                date. Continued use of the Service after changes take effect
                constitutes acceptance of the revised Terms.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                13. Governing Law
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                These Terms are governed by the laws of the Slovak Republic,
                without regard to conflict-of-law principles, unless otherwise
                required by mandatory local consumer protection law. This does
                not affect the license terms of AGPL-3.0 itself, which govern
                the source code independently of these Terms.
            </p>

            <h2
                class="mt-10 border-t border-border pt-8 text-xl font-semibold tracking-tight"
            >
                14. Contact
            </h2>
            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                Questions about these Terms can be sent to
                <a
                    href="mailto:contact@tadesrv.eu"
                    class="text-primary hover:underline"
                    >contact@tadesrv.eu</a
                >.
            </p>
        </main>

        <footer
            class="align-center flex justify-between border-t border-border bg-background px-6 py-4 text-xs text-muted-foreground"
        >
            <p>Made with sleep deprivation - 🄯2026 - 🄯2026</p>
            <p>
                <Link :href="privacyPolicy()" class="hover:underline"
                    >Privacy Policy</Link
                >
                |
                <Link :href="termsOfService()" class="hover:underline"
                    >Terms of Service</Link
                >
            </p>
        </footer>
    </div>
</template>
