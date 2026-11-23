<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

const props = withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <!-- Only navigation lives here: the trigger, and breadcrumbs when there is a level to go back to.
         Page titles are on the page itself, so nothing is said twice. -->
    <header class="flex h-12 shrink-0 items-center gap-3 px-4 pt-2 md:px-6">
        <SidebarTrigger class="-ml-1" />
        <Breadcrumbs
            v-if="props.breadcrumbs.length > 1"
            :breadcrumbs="props.breadcrumbs"
        />
    </header>
</template>
