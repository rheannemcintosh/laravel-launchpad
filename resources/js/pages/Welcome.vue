<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
</script>

<template>
    <Head title="Welcome" />

    <div
        class="bg-background text-foreground flex min-h-svh flex-col p-6 md:p-10"
    >
        <header class="flex w-full justify-end">
            <nav class="flex items-center gap-2 text-sm">
                <Link
                    v-if="page.props.auth.user"
                    :href="dashboard()"
                    class="hover:bg-accent rounded-md border px-4 py-1.5"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="hover:bg-accent rounded-md px-4 py-1.5"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="hover:bg-accent rounded-md border px-4 py-1.5"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>

        <main
            class="flex flex-1 flex-col items-center justify-center gap-6 text-center"
        >
            <div
                class="bg-primary text-primary-foreground flex size-16 items-center justify-center rounded-xl"
            >
                <AppLogoIcon class="size-9" />
            </div>
            <div class="space-y-2">
                <h1 class="text-4xl font-semibold tracking-tight">
                    {{ page.props.name }}
                </h1>
                <p class="text-muted-foreground text-lg">
                    {{ page.props.tagline }}
                </p>
            </div>
        </main>
    </div>
</template>
