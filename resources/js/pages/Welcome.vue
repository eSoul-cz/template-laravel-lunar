<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

defineProps<{
    name: string;
}>();
</script>

<template>
    <Head title="Welcome" />
    <AppLayout>
        <h1 class="text-3xl font-semibold tracking-tight">{{ name }}</h1>
        <p class="mt-2 text-muted-foreground">
            Inertia 3 + Vue 3 + Tailwind 4 are running. Replace this page in <code>resources/js/pages/Welcome.vue</code>.
        </p>
    </AppLayout>
</template>
