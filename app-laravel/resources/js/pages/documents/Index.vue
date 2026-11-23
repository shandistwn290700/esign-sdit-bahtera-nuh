<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, FilePlus } from '@lucide/vue';
import { computed } from 'vue';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import DocumentStatusBadge from '@/components/DocumentStatusBadge.vue';
import MdCard from '@/components/MdCard.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import type { DocumentItem } from '@/types/tte';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dokumen', href: DocumentController.index() }],
    },
});

const props = defineProps<{
    awaiting: DocumentItem[];
    created: DocumentItem[];
    stats: {
        awaiting: number;
        created_in_progress: number;
        created_completed: number;
    };
}>();

// The screen's job: see what needs my signature, then follow up on what I submitted.
const createdSummary = computed(() => {
    const parts = [];
    if (props.stats.created_in_progress) {
        parts.push(`${props.stats.created_in_progress} dalam proses`);
    }
    if (props.stats.created_completed) {
        parts.push(`${props.stats.created_completed} selesai`);
    }

    return parts.length ? parts.join(' · ') : 'Belum ada ajuan';
});

function progress(doc: DocumentItem): string {
    const signers = doc.signers ?? [];
    const signed = signers.filter((s) => s.status === 'signed').length;

    return signers.length ? `${signed} dari ${signers.length}` : '—';
}
</script>

<template>
    <Head title="Dokumen" />

    <div class="flex flex-col gap-8 p-4 md:px-6">
        <h1 class="sr-only">Dokumen</h1>

        <MdCard
            v-if="awaiting.length"
            :title="`${stats.awaiting} dokumen perlu tanda tangan Anda`"
            description="Penanda tangan berikutnya baru bisa menandatangani setelah Anda."
            tone="primary"
            flush
        >
            <ul class="divide-y">
                <li v-for="doc in awaiting" :key="doc.public_id">
                    <Link
                        :href="DocumentController.show(doc.public_id)"
                        class="flex items-center justify-between gap-4 px-6 py-4 hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ doc.title }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ doc.number ? `${doc.number} · ` : '' }}dari
                                {{ doc.creator?.name }}
                            </p>
                        </div>
                        <span
                            class="flex shrink-0 items-center gap-1 text-sm font-medium text-primary"
                        >
                            Tinjau
                            <ChevronRight class="size-4" aria-hidden="true" />
                        </span>
                    </Link>
                </li>
            </ul>
        </MdCard>
        <p
            v-else
            class="rounded-xl border border-dashed bg-card/50 px-6 py-4 text-sm text-muted-foreground"
        >
            Tidak ada dokumen yang perlu Anda tandatangani. Jika ada, Anda akan
            menerima email dan dokumennya muncul di sini.
        </p>

        <MdCard title="Ajuan Anda" :description="createdSummary" flush>
            <template #actions>
                <Button as-child size="sm">
                    <Link :href="DocumentController.create()">
                        <FilePlus class="size-4" />
                        Ajukan dokumen
                    </Link>
                </Button>
            </template>

            <p
                v-if="!created.length"
                class="px-6 py-6 text-sm text-muted-foreground"
            >
                Unggah PDF, pilih penanda tangan, lalu tentukan letak kotak QR
                untuk masing-masing. Mulai dengan “Ajukan dokumen”.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left">
                        <tr>
                            <th scope="col" class="px-6 py-3">Judul</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3">
                                Ditandatangani
                            </th>
                            <th scope="col" class="px-4 py-3">Diajukan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y border-t">
                        <tr v-for="doc in created" :key="doc.public_id">
                            <td class="px-6 py-3">
                                <Link
                                    :href="
                                        DocumentController.show(doc.public_id)
                                    "
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ doc.title }}
                                </Link>
                                <p
                                    v-if="doc.number"
                                    class="text-muted-foreground"
                                >
                                    {{ doc.number }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <DocumentStatusBadge v-bind="doc.status" />
                            </td>
                            <td
                                class="px-4 py-3 whitespace-nowrap tabular-nums"
                            >
                                {{ progress(doc) }}
                            </td>
                            <td
                                class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDateTime(doc.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </MdCard>
    </div>
</template>
