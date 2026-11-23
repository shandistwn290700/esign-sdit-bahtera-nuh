<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { computed } from 'vue';
import VerificationController from '@/actions/App/Http/Controllers/VerificationController';
import InputError from '@/components/InputError.vue';
import VerificationSummary from '@/components/VerificationSummary.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { chain } from '@/routes/pki';
import type { UploadVerificationResult } from '@/types/tte';

const props = defineProps<{ result: UploadVerificationResult | null }>();

const form = useForm({ file: null as File | null });

function submit(): void {
    form.post(VerificationController.check.url(), { forceFormData: true });
}

const intact = computed(
    () =>
        props.result?.signature_status === 'valid' &&
        !props.result.modified_after_last_signature,
);
</script>

<template>
    <Head title="Cek dokumen" />

    <div class="space-y-8">
        <div class="space-y-2">
            <h1 class="text-xl font-semibold">Cek keaslian berkas PDF</h1>
            <p class="text-sm text-muted-foreground">
                Unggah PDF yang Anda terima. Kami memeriksa tanda tangan
                elektroniknya dan mencocokkan berkas dengan catatan kami. Berkas
                tidak disimpan.
            </p>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="file">Berkas PDF</Label>
                <Input
                    id="file"
                    type="file"
                    accept="application/pdf"
                    required
                    @change="
                        form.file =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <InputError :message="form.errors.file" />
            </div>
            <Button type="submit" :disabled="!form.file || form.processing">
                {{ form.processing ? 'Memeriksa…' : 'Periksa' }}
            </Button>
        </form>

        <section
            v-if="result"
            aria-labelledby="result-heading"
            class="space-y-6"
            aria-live="polite"
        >
            <h2 id="result-heading" class="text-lg font-semibold">
                Hasil pemeriksaan
            </h2>

            <ul class="space-y-3 text-sm">
                <li class="flex gap-3">
                    <component
                        :is="intact ? CircleCheck : CircleAlert"
                        class="mt-0.5 size-5 shrink-0"
                        :class="intact ? 'text-green-600' : 'text-destructive'"
                        aria-hidden="true"
                    />
                    <span v-if="result.signature_status === 'unsigned'">
                        Berkas ini
                        <strong>tidak mengandung tanda tangan elektronik</strong
                        >.
                    </span>
                    <span v-else-if="intact">
                        Tanda tangan elektronik <strong>valid</strong> dan isi
                        berkas tidak berubah sejak ditandatangani.
                    </span>
                    <span v-else>
                        Tanda tangan <strong>tidak valid</strong>: berkas telah
                        diubah atau tidak ditandatangani oleh instansi ini.
                    </span>
                </li>
                <li class="flex gap-3">
                    <component
                        :is="result.registered ? CircleCheck : CircleAlert"
                        class="mt-0.5 size-5 shrink-0"
                        :class="
                            result.registered
                                ? 'text-green-600'
                                : 'text-destructive'
                        "
                        aria-hidden="true"
                    />
                    <span v-if="result.registered && result.is_latest_version">
                        Berkas ini <strong>persis sama</strong> dengan versi
                        terakhir yang tercatat di sistem kami.
                    </span>
                    <span v-else-if="result.registered">
                        Berkas tercatat, tetapi
                        <strong>bukan versi terakhir</strong>. Masih ada tanda
                        tangan yang ditambahkan setelahnya.
                    </span>
                    <span v-else>
                        Berkas ini <strong>tidak ditemukan</strong> di catatan
                        kami.
                    </span>
                </li>
            </ul>

            <div v-if="result.signatures.length" class="space-y-2">
                <h3 class="text-sm font-semibold">
                    Tanda tangan di dalam berkas
                </h3>
                <ul class="divide-y rounded-md border text-sm">
                    <li
                        v-for="(s, i) in result.signatures"
                        :key="i"
                        class="px-4 py-3"
                    >
                        <div class="flex flex-wrap justify-between gap-2">
                            <p class="font-medium">
                                {{ s.signer_name ?? 'Tidak dikenal' }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ formatDateTime(s.signed_at) }}
                            </p>
                        </div>
                        <p
                            :class="
                                s.valid
                                    ? 'text-green-700 dark:text-green-400'
                                    : 'text-destructive'
                            "
                        >
                            {{ s.valid ? 'Valid' : 'Tidak valid'
                            }}{{ s.revoked ? ' · sertifikat dicabut' : '' }}
                        </p>
                        <ul
                            v-if="s.problems.length"
                            class="mt-1 list-disc pl-5 text-muted-foreground"
                        >
                            <li v-for="p in s.problems" :key="p">{{ p }}</li>
                        </ul>
                    </li>
                </ul>
            </div>

            <VerificationSummary
                v-if="result.verification"
                :verification="result.verification"
            />
        </section>

        <p class="text-xs text-muted-foreground">
            Pembaca PDF seperti Adobe menampilkan tanda tangan ini sebagai
            "identitas tidak dikenal" karena diterbitkan oleh CA internal
            instansi. Halaman ini adalah rujukan resminya.
            <a :href="chain.url()" class="underline underline-offset-4"
                >Unduh sertifikat CA</a
            >.
        </p>
    </div>
</template>
