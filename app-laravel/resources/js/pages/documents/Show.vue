<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check, Copy, Download, ExternalLink } from '@lucide/vue';
import { ref } from 'vue';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import RevokedDocumentController from '@/actions/App/Http/Controllers/RevokedDocumentController';
import DocumentStatusBadge from '@/components/DocumentStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import MdCard from '@/components/MdCard.vue';
import InputError from '@/components/InputError.vue';
import OtpField from '@/components/OtpField.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import type { DocumentItem, SignerStepStatus } from '@/types/tte';

const props = defineProps<{
    document: DocumentItem;
    can: { sign: boolean; revoke: boolean; update: boolean; delete: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dokumen', href: DocumentController.index() }],
    },
});

const code = ref('');
const copied = ref(false);
const rejectOpen = ref(false);
const revokeOpen = ref(false);
const editOpen = ref(false);
const deleteOpen = ref(false);
const deleteConfirm = ref('');

const stepLabel: Record<SignerStepStatus, string> = {
    waiting: 'Menunggu giliran',
    pending: 'Giliran sekarang',
    signed: 'Sudah menandatangani',
    rejected: 'Menolak',
    skipped: 'Dilewati',
};

async function copyLink(): Promise<void> {
    await navigator.clipboard.writeText(props.document.verify_url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

const textareaClass =
    'min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
</script>

<template>
    <Head :title="document.title" />

    <div class="flex flex-col gap-8 p-4 md:px-6 lg:flex-row lg:items-start">
        <div
            class="flex min-w-0 flex-1 flex-col gap-6 rounded-xl bg-card p-6 text-card-foreground shadow-card"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    :title="document.title"
                    :description="document.subject ?? undefined"
                />
                <DocumentStatusBadge v-bind="document.status" />
            </div>

            <Alert
                v-if="document.status.value === 'revoked'"
                variant="destructive"
            >
                <AlertTitle>Dokumen dicabut</AlertTitle>
                <AlertDescription>
                    Dicabut {{ formatDateTime(document.revoked_at) }}.
                    {{ document.revocation_reason }}
                </AlertDescription>
            </Alert>

            <dl
                class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[max-content_1fr]"
            >
                <dt class="text-muted-foreground">Nomor</dt>
                <dd>{{ document.number ?? '—' }}</dd>
                <dt class="text-muted-foreground">Diajukan oleh</dt>
                <dd>
                    {{ document.creator?.name }},
                    {{ formatDateTime(document.created_at) }}
                </dd>
                <dt class="text-muted-foreground">Selesai</dt>
                <dd>{{ formatDateTime(document.completed_at) }}</dd>
                <dt class="text-muted-foreground">Tautan verifikasi</dt>
                <dd class="flex min-w-0 items-center gap-2">
                    <code
                        class="truncate rounded bg-muted px-1.5 py-0.5 text-xs"
                    >
                        {{ document.verify_url }}
                    </code>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="
                            copied ? 'Tersalin' : 'Salin tautan verifikasi'
                        "
                        @click="copyLink"
                    >
                        <Check v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                    </Button>
                </dd>
            </dl>

            <div class="flex flex-wrap gap-2">
                <Button as-child>
                    <a
                        :href="
                            DocumentController.file.url(document.public_id, {
                                query: { download: 1 },
                            })
                        "
                        download
                        data-test="download-pdf"
                    >
                        <Download class="size-4" />
                        Unduh PDF
                    </a>
                </Button>
                <Button variant="outline" as-child>
                    <a
                        :href="DocumentController.file.url(document.public_id)"
                        target="_blank"
                        rel="noopener"
                    >
                        <ExternalLink class="size-4" />
                        Lihat di tab baru
                    </a>
                </Button>
            </div>
            <p
                v-if="document.status.value === 'in_progress'"
                class="text-sm text-muted-foreground"
            >
                Dokumen belum selesai: PDF yang diunduh berisi tanda tangan yang
                sudah ada sejauh ini.
            </p>

            <section aria-labelledby="signers-heading" class="space-y-3">
                <h2 id="signers-heading" class="text-base font-semibold">
                    Urutan penanda tangan
                </h2>
                <ol class="divide-y rounded-md border">
                    <li
                        v-for="s in document.signers ?? []"
                        :key="s.order"
                        class="flex flex-wrap items-start justify-between gap-2 px-4 py-3 text-sm"
                        :aria-current="
                            s.status === 'pending' ? 'step' : undefined
                        "
                    >
                        <div>
                            <p class="font-medium">
                                <span class="tabular-nums">{{ s.order }}.</span>
                                {{ s.user?.name }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ s.user?.position ?? '' }}
                            </p>
                            <p v-if="s.rejection_reason" class="mt-1">
                                Alasan: {{ s.rejection_reason }}
                            </p>
                        </div>
                        <div class="text-right">
                            <p
                                :class="
                                    s.status === 'pending'
                                        ? 'font-medium text-primary'
                                        : ''
                                "
                            >
                                {{ stepLabel[s.status] }}
                            </p>
                            <p v-if="s.signed_at" class="text-muted-foreground">
                                {{ formatDateTime(s.signed_at) }}
                            </p>
                        </div>
                    </li>
                </ol>
            </section>
        </div>

        <aside class="flex w-full flex-col gap-6 lg:w-80">
            <MdCard v-if="can.sign" title="Tandatangani dokumen" tone="primary">
                <p class="text-sm text-muted-foreground">
                    Buka PDF dan periksa isinya terlebih dahulu. Masukkan kode 6
                    digit dari aplikasi autentikator
                    <strong>tanda tangan</strong> (bukan kode login).
                </p>
                <Form
                    v-bind="DocumentController.sign.form(document.public_id)"
                    class="space-y-4"
                    :options="{ preserveScroll: true }"
                    @finish="code = ''"
                    v-slot="{ errors, processing }"
                >
                    <Label for="totp_code">Kode tanda tangan</Label>
                    <OtpField
                        v-model="code"
                        :error="errors.totp_code"
                        :disabled="processing"
                    />
                    <InputError :message="errors.action" />
                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="processing || code.length < 6"
                    >
                        {{ processing ? 'Menandatangani…' : 'Tandatangani' }}
                    </Button>
                </Form>

                <Dialog v-model:open="rejectOpen">
                    <DialogTrigger as-child>
                        <Button variant="ghost" class="w-full"
                            >Tolak dokumen</Button
                        >
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="
                                DocumentController.reject.form(
                                    document.public_id,
                                )
                            "
                            class="space-y-4"
                            @success="rejectOpen = false"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader>
                                <DialogTitle>Tolak dokumen?</DialogTitle>
                                <DialogDescription>
                                    Dokumen dikembalikan ke pembuat dan penanda
                                    tangan berikutnya dilewati.
                                </DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="reject-reason">Alasan</Label>
                                <textarea
                                    id="reject-reason"
                                    name="reason"
                                    required
                                    minlength="5"
                                    maxlength="500"
                                    :class="textareaClass"
                                />
                                <InputError
                                    :message="errors.reason ?? errors.action"
                                />
                            </div>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button type="button" variant="outline"
                                        >Batal</Button
                                    >
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                >
                                    Tolak
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </MdCard>

            <MdCard v-if="can.revoke" title="Cabut dokumen">
                <p class="text-sm text-muted-foreground">
                    Halaman verifikasi akan menampilkan DICABUT. Tindakan ini
                    tidak dapat dibatalkan.
                </p>
                <Dialog v-model:open="revokeOpen">
                    <DialogTrigger as-child>
                        <Button variant="destructive" class="w-full"
                            >Cabut dokumen</Button
                        >
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="
                                DocumentController.revoke.form(
                                    document.public_id,
                                )
                            "
                            class="space-y-4"
                            @success="revokeOpen = false"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader>
                                <DialogTitle>Cabut dokumen ini?</DialogTitle>
                                <DialogDescription>
                                    Siapa pun yang memindai QR-nya akan melihat
                                    status DICABUT.
                                </DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="revoke-reason">Alasan</Label>
                                <textarea
                                    id="revoke-reason"
                                    name="reason"
                                    required
                                    minlength="5"
                                    maxlength="500"
                                    :class="textareaClass"
                                />
                                <InputError
                                    :message="errors.reason ?? errors.action"
                                />
                            </div>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button type="button" variant="outline"
                                        >Batal</Button
                                    >
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                >
                                    Cabut
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </MdCard>

            <!-- Revoked documents only: edit metadata, or delete permanently. -->
            <MdCard
                v-if="can.update || can.delete"
                title="Kelola dokumen dicabut"
                description="Tanda tangannya sudah tidak berlaku."
            >
                <Dialog v-if="can.update" v-model:open="editOpen">
                    <DialogTrigger as-child>
                        <Button variant="outline" class="w-full"
                            >Ubah keterangan</Button
                        >
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="
                                RevokedDocumentController.update.form(
                                    document.public_id,
                                )
                            "
                            class="space-y-4"
                            :options="{ preserveScroll: true }"
                            @success="editOpen = false"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader>
                                <DialogTitle>Ubah keterangan</DialogTitle>
                                <DialogDescription>
                                    Berkas PDF yang sudah ditandatangani tidak
                                    berubah. Perubahan tercatat di audit log.
                                </DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="edit-title">Judul</Label>
                                <Input
                                    id="edit-title"
                                    name="title"
                                    :default-value="document.title"
                                    required
                                    maxlength="200"
                                />
                                <InputError :message="errors.title" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="edit-number">Nomor surat</Label>
                                <Input
                                    id="edit-number"
                                    name="number"
                                    :default-value="document.number ?? ''"
                                    maxlength="100"
                                />
                                <InputError :message="errors.number" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="edit-subject">Perihal</Label>
                                <Input
                                    id="edit-subject"
                                    name="subject"
                                    :default-value="document.subject ?? ''"
                                    maxlength="255"
                                />
                                <InputError :message="errors.subject" />
                            </div>
                            <label class="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="show_metadata_publicly"
                                    value="1"
                                    class="mt-0.5 size-4"
                                    :checked="document.show_metadata_publicly"
                                />
                                Tampilkan judul dan nomor di halaman verifikasi
                                publik
                            </label>
                            <InputError :message="errors.action" />
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button type="button" variant="outline"
                                        >Batal</Button
                                    >
                                </DialogClose>
                                <Button type="submit" :disabled="processing"
                                    >Simpan</Button
                                >
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>

                <Dialog v-if="can.delete" v-model:open="deleteOpen">
                    <DialogTrigger as-child>
                        <Button variant="destructive" class="w-full"
                            >Hapus permanen</Button
                        >
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="
                                RevokedDocumentController.destroy.form(
                                    document.public_id,
                                )
                            "
                            class="space-y-4"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader>
                                <DialogTitle
                                    >Hapus “{{ document.title }}”
                                    permanen?</DialogTitle
                                >
                                <DialogDescription>
                                    Dokumen, semua versi PDF, dan urutan penanda
                                    tangannya dihapus dan tidak dapat
                                    dikembalikan. QR yang sudah tercetak akan
                                    menampilkan “dokumen tidak ditemukan”, bukan
                                    DICABUT. Catatan penghapusan tetap ada di
                                    audit log.
                                </DialogDescription>
                            </DialogHeader>
                            <div class="grid gap-2">
                                <Label for="delete-confirm"
                                    >Ketik HAPUS untuk mengonfirmasi</Label
                                >
                                <Input
                                    id="delete-confirm"
                                    name="confirmation"
                                    required
                                    autocomplete="off"
                                    v-model="deleteConfirm"
                                />
                                <InputError
                                    :message="
                                        errors.confirmation ?? errors.action
                                    "
                                />
                            </div>
                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button type="button" variant="outline"
                                        >Batal</Button
                                    >
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="
                                        processing || deleteConfirm !== 'HAPUS'
                                    "
                                >
                                    Hapus permanen
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </MdCard>
        </aside>
    </div>
</template>
