<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage();
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col items-center justify-center gap-4 p-4 text-center"
    >
        <div
            class="bg-primary text-primary-foreground flex size-14 items-center justify-center rounded-xl"
        >
            <AppLogoIcon class="size-8" />
        </div>
        <div class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">
                Welcome to {{ page.props.name }}
            </h1>
            <p class="text-muted-foreground text-sm">
                {{ page.props.tagline }}
            </p>
        </div>
    </div>
</template>
