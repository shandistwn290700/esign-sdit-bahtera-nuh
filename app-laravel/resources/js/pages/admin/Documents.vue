<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { ref } from 'vue';
import AdminDocumentController from '@/actions/App/Http/Controllers/Admin/DocumentController';
import DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import DocumentStatusBadge from '@/components/DocumentStatusBadge.vue';
import MdCard from '@/components/MdCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import type { DocumentItem, DocumentStatusValue } from '@/types/tte';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Semua dokumen', href: AdminDocumentController.index() },
        ],
    },
});

const props = defineProps<{
    documents: DocumentItem[];
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_url: string | null;
        next_url: string | null;
    };
    filters: { q: string; status: DocumentStatusValue | null };
    counts: Record<DocumentStatusValue, { label: string; total: number }>;
}>();

const q = ref(props.filters.q);

function visit(status: DocumentStatusValue | null, query = q.value): void {
    router.get(
        AdminDocumentController.index.url(),
        { q: query || undefined, status: status ?? undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function allTotal(): number {
    return Object.values(props.counts).reduce((sum, c) => sum + c.total, 0);
}

function progress(doc: DocumentItem): string {
    const signers = doc.signers ?? [];
    const signed = signers.filter((s) => s.status === 'signed').length;

    return signers.length ? `${signed}/${signers.length}` : '—';
}

function currentSigner(doc: DocumentItem): string | null {
    if (doc.status.value !== 'in_progress') {
        return null;
    }

    return (
        doc.signers?.find((s) => s.order === doc.current_step)?.user?.name ??
        null
    );
}

const tabClass = (active: boolean): string =>
    [
        'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
        active
            ? 'bg-primary text-primary-foreground'
            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
    ].join(' ');
</script>

<template>
    <Head title="Semua dokumen" />

    <div class="flex flex-col gap-6 p-4 pt-6 md:px-6">
        <h1 class="sr-only">Semua dokumen</h1>
        <MdCard
            title="Semua dokumen"
            description="Seluruh dokumen di instansi. Hanya admin yang dapat melihat halaman ini."
            flush
        >
            <div class="flex flex-col gap-4 px-6 pt-2 pb-4">
                <form
                    role="search"
                    class="flex max-w-xl gap-2"
                    @submit.prevent="visit(filters.status)"
                >
                    <label for="q" class="sr-only">Cari dokumen</label>
                    <Input
                        id="q"
                        v-model="q"
                        type="search"
                        maxlength="100"
                        placeholder="Cari judul, nomor surat, atau nama pembuat"
                    />
                    <Button type="submit" variant="secondary">
                        <Search class="size-4" />
                        Cari
                    </Button>
                </form>

                <nav aria-label="Filter status" class="flex flex-wrap gap-1">
                    <button
                        type="button"
                        :class="tabClass(filters.status === null)"
                        :aria-current="
                            filters.status === null ? 'true' : undefined
                        "
                        @click="visit(null)"
                    >
                        Semua
                        <span class="tabular-nums opacity-70">{{
                            allTotal()
                        }}</span>
                    </button>
                    <button
                        v-for="(c, value) in counts"
                        :key="value"
                        type="button"
                        :class="tabClass(filters.status === value)"
                        :aria-current="
                            filters.status === value ? 'true' : undefined
                        "
                        @click="visit(value)"
                    >
                        {{ c.label }}
                        <span class="tabular-nums opacity-70">{{
                            c.total
                        }}</span>
                    </button>
                </nav>
            </div>
            <p
                v-if="!documents.length"
                class="border-t px-6 py-6 text-sm text-muted-foreground"
            >
                {{
                    filters.q || filters.status
                        ? 'Tidak ada dokumen yang cocok dengan pencarian atau filter ini.'
                        : 'Belum ada dokumen.'
                }}
            </p>

            <div v-else class="overflow-x-auto border-t">
                <table class="w-full text-sm">
                    <thead class="text-left">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Judul
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Pembuat
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Status
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Tanda tangan
                            </th>
                            <th scope="col" class="px-4 py-2 font-medium">
                                Diajukan
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="doc in documents" :key="doc.public_id">
                            <td class="max-w-xs px-4 py-2">
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
                            <td class="px-4 py-2 whitespace-nowrap">
                                {{ doc.creator?.name }}
                            </td>
                            <td class="px-4 py-2">
                                <DocumentStatusBadge v-bind="doc.status" />
                                <p
                                    v-if="currentSigner(doc)"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Menunggu {{ currentSigner(doc) }}
                                </p>
                            </td>
                            <td class="px-4 py-2 tabular-nums">
                                {{ progress(doc) }}
                            </td>
                            <td
                                class="px-4 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDateTime(doc.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="pagination.last_page > 1"
                aria-label="Halaman"
                class="flex flex-wrap items-center justify-between gap-2 border-t px-6 pt-3 text-sm"
            >
                <p class="text-muted-foreground tabular-nums">
                    {{ pagination.from }}–{{ pagination.to }} dari
                    {{ pagination.total }} dokumen
                </p>
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!pagination.prev_url"
                        :as-child="!!pagination.prev_url"
                    >
                        <Link
                            v-if="pagination.prev_url"
                            :href="pagination.prev_url"
                            preserve-scroll
                            >Sebelumnya</Link
                        >
                        <template v-else>Sebelumnya</template>
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!pagination.next_url"
                        :as-child="!!pagination.next_url"
                    >
                        <Link
                            v-if="pagination.next_url"
                            :href="pagination.next_url"
                            preserve-scroll
                            >Berikutnya</Link
                        >
                        <template v-else>Berikutnya</template>
                    </Button>
                </div>
            </nav>
        </MdCard>
    </div>
</template>
