<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import Heading from '@/components/Heading.vue';
import MdCard from '@/components/MdCard.vue';
import InputError from '@/components/InputError.vue';
import PdfPlacer from '@/components/PdfPlacer.vue';
import type { Placement } from '@/components/PdfPlacer.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SignerOption } from '@/types/tte';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dokumen', href: DocumentController.index() },
            { title: 'Ajukan dokumen', href: DocumentController.create() },
        ],
    },
});

const props = defineProps<{ signers: SignerOption[]; maxUploadKb: number }>();

const form = useForm({
    file: null as File | null,
    title: '',
    number: '',
    subject: '',
    show_metadata_publicly: true,
    signers: [] as Array<{ user_id: number } & Placement>,
});

/** Signer user ids in signing order. */
const chosen = ref<number[]>([]);
const placements = ref<Record<number, Placement>>({});
const pick = ref<string>('');
const fileError = ref<string | null>(null);

const available = computed(() =>
    props.signers.filter((s) => !chosen.value.includes(s.id)),
);
const chosenSigners = computed(() =>
    chosen.value.map((id) => {
        const s = props.signers.find((x) => x.id === id)!;

        return {
            key: id,
            label: s.position ? `${s.name} — ${s.position}` : s.name,
        };
    }),
);
const missingPlacement = computed(() =>
    chosen.value.filter((id) => !placements.value[id]),
);
const canSubmit = computed(
    () =>
        !!form.file &&
        form.title.trim() !== '' &&
        chosen.value.length > 0 &&
        missingPlacement.value.length === 0 &&
        !form.processing,
);

function onFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    fileError.value = null;
    if (file && file.type !== 'application/pdf') {
        fileError.value = 'Pilih berkas PDF.';
        form.file = null;

        return;
    }
    if (file && file.size > props.maxUploadKb * 1024) {
        fileError.value = `Ukuran maksimal ${Math.round(props.maxUploadKb / 1024)} MB.`;
        form.file = null;

        return;
    }
    form.file = file;
    placements.value = {};
}

function addSigner(): void {
    const id = Number(pick.value);
    if (id && !chosen.value.includes(id) && chosen.value.length < 10) {
        chosen.value.push(id);
    }
    pick.value = '';
}

function move(index: number, delta: number): void {
    const target = index + delta;
    if (target < 0 || target >= chosen.value.length) {
        return;
    }
    const next = [...chosen.value];
    [next[index], next[target]] = [next[target], next[index]];
    chosen.value = next;
}

function removeSigner(id: number): void {
    chosen.value = chosen.value.filter((x) => x !== id);
    const next = { ...placements.value };
    delete next[id];
    placements.value = next;
}

function submit(): void {
    form.signers = chosen.value.map((id) => ({
        user_id: id,
        ...placements.value[id],
    }));
    form.post(DocumentController.store.url(), { forceFormData: true });
}

const signerErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('signers'))
        .map(([, message]) => message),
);
</script>

<template>
    <Head title="Ajukan dokumen" />

    <form class="flex flex-col gap-8 p-4 md:px-6" @submit.prevent="submit">
        <Heading
            title="Ajukan dokumen"
            description="Unggah PDF, tentukan urutan penanda tangan, lalu taruh kotak QR untuk masing-masing."
        />

        <div class="grid gap-8 xl:grid-cols-2 xl:items-start">
            <MdCard
                title="1. Dokumen"
                description="Berkas PDF dan keterangannya"
            >
                <div class="grid gap-2">
                    <Label for="file">Berkas PDF</Label>
                    <Input
                        id="file"
                        type="file"
                        accept="application/pdf"
                        required
                        @change="onFile"
                    />
                    <p class="text-xs text-muted-foreground">
                        Maksimal {{ Math.round(maxUploadKb / 1024) }} MB, tidak
                        berpassword.
                    </p>
                    <InputError :message="fileError ?? form.errors.file" />
                </div>

                <div class="grid gap-2">
                    <Label for="title">Judul</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        required
                        maxlength="200"
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="number">Nomor surat (opsional)</Label>
                        <Input
                            id="number"
                            v-model="form.number"
                            maxlength="100"
                        />
                        <InputError :message="form.errors.number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="subject">Perihal (opsional)</Label>
                        <Input
                            id="subject"
                            v-model="form.subject"
                            maxlength="255"
                        />
                        <InputError :message="form.errors.subject" />
                    </div>
                </div>

                <div class="flex items-start gap-3">
                    <Checkbox
                        id="show_metadata_publicly"
                        v-model="form.show_metadata_publicly"
                    />
                    <div class="grid gap-1">
                        <Label for="show_metadata_publicly">
                            Tampilkan judul dan nomor di halaman verifikasi
                            publik
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            Matikan untuk dokumen rahasia. Isi dokumen tidak
                            pernah ditampilkan.
                        </p>
                    </div>
                </div>
            </MdCard>

            <MdCard
                title="2. Penanda tangan"
                description="Ditandatangani berurutan dari atas ke bawah"
            >
                <p
                    v-if="!signers.length"
                    class="rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                >
                    Belum ada pengguna yang dapat menandatangani. Minta admin
                    mengaktifkan tanda tangan, lalu penanda tangan mengaktifkan
                    autentikatornya.
                </p>

                <div v-else class="flex gap-2">
                    <label for="pick" class="sr-only"
                        >Pilih penanda tangan</label
                    >
                    <select
                        id="pick"
                        v-model="pick"
                        class="h-9 flex-1 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <option value="" disabled>Pilih penanda tangan…</option>
                        <option
                            v-for="s in available"
                            :key="s.id"
                            :value="String(s.id)"
                        >
                            {{ s.name
                            }}{{ s.position ? ` — ${s.position}` : '' }}
                        </option>
                    </select>
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="!pick || chosen.length >= 10"
                        @click="addSigner"
                    >
                        Tambah
                    </Button>
                </div>

                <ol
                    v-if="chosenSigners.length"
                    class="divide-y rounded-md border"
                >
                    <li
                        v-for="(s, i) in chosenSigners"
                        :key="s.key"
                        class="flex items-center gap-2 px-3 py-2 text-sm"
                    >
                        <span class="w-6 font-medium tabular-nums"
                            >{{ i + 1 }}.</span
                        >
                        <span class="flex-1">{{ s.label }}</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="i === 0"
                            :aria-label="`Naikkan ${s.label}`"
                            @click="move(i, -1)"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :disabled="i === chosenSigners.length - 1"
                            :aria-label="`Turunkan ${s.label}`"
                            @click="move(i, 1)"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Hapus ${s.label}`"
                            @click="removeSigner(s.key)"
                        >
                            <X class="size-4" />
                        </Button>
                    </li>
                </ol>
                <InputError
                    v-for="(message, i) in signerErrors"
                    :key="i"
                    :message="message"
                />
            </MdCard>
        </div>

        <MdCard
            title="3. Posisi kotak QR"
            description="Geser kotak ke tempat tanda tangan di halaman PDF"
        >
            <p
                v-if="!form.file || !chosen.length"
                class="text-sm text-muted-foreground"
            >
                Unggah PDF dan pilih penanda tangan terlebih dahulu.
            </p>
            <PdfPlacer
                v-else
                v-model="placements"
                :file="form.file"
                :signers="chosenSigners"
            />
        </MdCard>

        <div class="flex flex-wrap items-center gap-4">
            <Button type="submit" :disabled="!canSubmit">
                {{
                    form.processing
                        ? 'Mengirim…'
                        : 'Ajukan untuk ditandatangani'
                }}
            </Button>
            <p
                v-if="chosen.length && missingPlacement.length"
                class="text-sm text-muted-foreground"
            >
                {{ missingPlacement.length }} penanda tangan belum punya kotak
                QR.
            </p>
        </div>
    </form>
</template>
