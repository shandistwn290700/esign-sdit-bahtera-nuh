<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
</script>

<template>
    <!-- Sign-in: same dark surface as the sidebar, one white card whose green header strip
         carries the school's logo and name (the only green on this screen besides the submit). -->
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-gradient-dark px-4 py-12"
    >
        <main class="w-full max-w-sm pt-5">
            <div class="rounded-xl bg-card text-card-foreground shadow-lg">
                <div
                    class="mx-4 -mt-5 flex items-center gap-3 rounded-lg bg-primary px-4 py-4 text-primary-foreground shadow-sm"
                >
                    <Link
                        :href="home()"
                        class="flex size-11 shrink-0 items-center justify-center rounded-md bg-white p-1.5 text-primary"
                    >
                        <AppLogoIcon class="size-full" />
                        <span class="sr-only"
                            >Beranda {{ page.props.name }}</span
                        >
                    </Link>
                    <div class="min-w-0">
                        <p class="text-sm leading-tight opacity-90">
                            {{ page.props.name }}
                        </p>
                        <h1 class="text-lg leading-snug font-semibold">
                            {{ title }}
                        </h1>
                    </div>
                </div>
                <div class="space-y-6 p-6">
                    <p v-if="description" class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
