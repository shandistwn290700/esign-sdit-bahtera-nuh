<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import SignatureController from '@/actions/App/Http/Controllers/Settings/SignatureController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import OtpField from '@/components/OtpField.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/signature';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tanda tangan', href: edit() }],
    },
});

defineProps<{
    signature: {
        enabled: boolean;
        activated: boolean;
        revoked: boolean;
        certificate_serial: string | null;
        name: string;
        position: string | null;
        nip: string | null;
    };
    activation: { qr_svg: string; manual_key: string | null } | null;
}>();

const code = ref('');
</script>

<template>
    <Head title="Tanda tangan" />

    <h1 class="sr-only">Tanda tangan</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Tanda tangan elektronik"
            description="Autentikator khusus untuk menandatangani dokumen, terpisah dari kode login Anda."
        />

        <Alert v-if="signature.revoked" variant="destructive">
            <AlertTitle>Sertifikat dicabut</AlertTitle>
            <AlertDescription>
                Anda tidak dapat menandatangani dokumen. Hubungi admin jika ini
                keliru.
            </AlertDescription>
        </Alert>

        <p
            v-else-if="!signature.enabled"
            class="rounded-md border border-dashed p-4 text-sm text-muted-foreground"
        >
            Tanda tangan belum diaktifkan untuk akun Anda. Minta admin
            mengaktifkannya, lalu kembali ke halaman ini.
        </p>

        <template v-else-if="signature.activated">
            <Alert>
                <AlertTitle>Autentikator tanda tangan aktif</AlertTitle>
                <AlertDescription>
                    Anda dapat menandatangani dokumen. Jika HP Anda hilang,
                    segera minta admin mereset autentikator.
                </AlertDescription>
            </Alert>
            <dl
                class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]"
            >
                <dt class="text-muted-foreground">Nama di stempel</dt>
                <dd>{{ signature.name }}</dd>
                <dt class="text-muted-foreground">Jabatan</dt>
                <dd>{{ signature.position ?? '—' }}</dd>
                <dt class="text-muted-foreground">NIP</dt>
                <dd>{{ signature.nip ?? '—' }}</dd>
                <dt class="text-muted-foreground">No. seri sertifikat</dt>
                <dd>
                    <code class="text-xs">{{
                        signature.certificate_serial
                    }}</code>
                </dd>
            </dl>
        </template>

        <template v-else>
            <ol
                class="list-decimal space-y-1 pl-5 text-sm text-muted-foreground"
            >
                <li>
                    Pasang aplikasi autentikator di HP (Google Authenticator,
                    Microsoft Authenticator, atau sejenisnya).
                </li>
                <li>
                    Klik <strong>Tampilkan kode QR</strong> dan pindai dengan
                    aplikasi tersebut.
                </li>
                <li>Masukkan kode 6 digit yang muncul untuk mengonfirmasi.</li>
            </ol>

            <Form
                v-if="!activation"
                v-bind="SignatureController.start.form()"
                v-slot="{ errors, processing }"
            >
                <Button type="submit" :disabled="processing"
                    >Tampilkan kode QR</Button
                >
                <InputError class="mt-2" :message="errors.action" />
            </Form>

            <div v-else class="space-y-6">
                <div class="space-y-3">
                    <!-- SVG generated server-side by BaconQrCode from the otpauth URI. -->
                    <div
                        class="w-fit rounded-md border bg-white p-3"
                        role="img"
                        aria-label="Kode QR untuk aplikasi autentikator"
                        v-html="activation.qr_svg"
                    />
                    <p v-if="activation.manual_key" class="text-sm">
                        Tidak bisa memindai? Masukkan kunci ini:
                        <code
                            class="rounded bg-muted px-1.5 py-0.5 text-xs break-all"
                            >{{ activation.manual_key }}</code
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Kode QR ini hanya ditampilkan sekali. Jangan bagikan
                        atau tangkap layar.
                    </p>
                </div>

                <Form
                    v-bind="SignatureController.confirm.form()"
                    class="space-y-3"
                    @finish="code = ''"
                    v-slot="{ errors, processing }"
                >
                    <Label for="totp_code">Kode dari aplikasi</Label>
                    <OtpField
                        v-model="code"
                        :error="errors.totp_code"
                        :disabled="processing"
                        autofocus
                    />
                    <InputError :message="errors.action" />
                    <Button
                        type="submit"
                        :disabled="processing || code.length < 6"
                        >Aktifkan</Button
                    >
                </Form>
            </div>
        </template>
    </div>
</template>
