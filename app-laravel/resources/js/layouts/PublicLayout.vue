<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';
import { form as verifyForm } from '@/routes/verify';

const page = usePage();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header
            class="mx-4 mt-4 rounded-xl bg-card shadow-card sm:mx-auto sm:w-full sm:max-w-3xl"
        >
            <div
                class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3"
            >
                <Link :href="home()" class="flex items-center gap-2 font-bold">
                    <AppLogoIcon class="size-7 text-primary" />
                    <span>{{ page.props.name }}</span>
                </Link>
                <Link
                    :href="verifyForm()"
                    class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Cek dokumen
                </Link>
            </div>
        </header>
        <main class="mx-auto w-full max-w-3xl flex-1 px-4 py-8 sm:px-0">
            <div
                class="rounded-xl bg-card p-6 text-card-foreground shadow-card"
            >
                <slot />
            </div>
        </main>
    </div>
</template>
