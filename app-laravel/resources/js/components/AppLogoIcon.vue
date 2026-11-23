<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { FileSignature } from '@lucide/vue';
import { computed } from 'vue';

/** The institution logo uploaded in "Identitas aplikasi", or a neutral signature icon. */
const page = usePage();
const logoUrl = computed(() => page.props.branding?.logo_url ?? null);
</script>

<template>
    <img
        v-if="logoUrl"
        :src="logoUrl"
        :alt="`Logo ${page.props.name}`"
        class="object-contain"
    />
    <FileSignature v-else aria-hidden="true" />
</template>
