<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import VerificationSummary from '@/components/VerificationSummary.vue';
import { form as verifyForm } from '@/routes/verify';
import type { PublicVerification } from '@/types/tte';

defineProps<{ verification: PublicVerification }>();
</script>

<template>
    <Head title="Verifikasi dokumen" />

    <div class="space-y-8">
        <h1 class="text-xl font-semibold">Verifikasi dokumen</h1>

        <VerificationSummary :verification="verification" />

        <div class="space-y-2 rounded-md border bg-muted/40 p-4 text-sm">
            <p class="font-medium">
                Ingin memastikan berkas yang Anda pegang asli?
            </p>
            <p class="text-muted-foreground">
                Halaman ini membuktikan bahwa dokumen dengan QR ini terdaftar.
                Untuk memastikan isi berkas PDF tidak diubah, unggah berkasnya
                di halaman cek dokumen.
            </p>
            <Link
                :href="verifyForm()"
                class="inline-block font-medium underline underline-offset-4"
            >
                Cek berkas PDF
            </Link>
        </div>
    </div>
</template>
