<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const name = usePage().props.name;
</script>

<template>
    <div
        class="flex aspect-square size-8 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white p-0.5 text-primary"
    >
        <AppLogoIcon class="size-full max-h-6 max-w-6" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="line-clamp-2 leading-tight font-bold text-white">{{
            name
        }}</span>
    </div>
</template>
