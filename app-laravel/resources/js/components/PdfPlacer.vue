<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import type {
    PageViewport,
    PDFDocumentLoadingTask,
    PDFDocumentProxy,
} from 'pdfjs-dist';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import type { Box } from '@/types/tte';

/**
 * Renders a PDF with pdf.js and lets the user drag one QR box per signer.
 * Placements are emitted in PDF points with the origin at the bottom-left of the
 * page (signing-service/openapi.yaml), converted through the pdf.js viewport so
 * rotated pages are handled too.
 */
export type Placement = Box & { page: number };
export type PlacerSigner = { key: number; label: string };

const props = defineProps<{ file: File; signers: PlacerSigner[] }>();
const placements = defineModel<Record<number, Placement>>({ required: true });

// Default stamp: 200 x 120 pt keeps the QR comfortably scannable when printed.
const DEFAULT_SIZE = { width: 200, height: 120 };
const MAX_CSS_WIDTH = 720;

const pdf = shallowRef<PDFDocumentProxy | null>(null);
const pageNumber = ref(1);
const viewport = shallowRef<PageViewport | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const loadError = ref<string | null>(null);
const dragging = ref<number | null>(null);
let dragOffset = { x: 0, y: 0 };
let loadingTask: PDFDocumentLoadingTask | null = null;
let renderTask: { cancel: () => void; promise: Promise<unknown> } | null = null;

const pageCount = computed(() => pdf.value?.numPages ?? 0);

async function loadPdf(file: File): Promise<void> {
    loadError.value = null;
    const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
    const worker =
        await import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url');
    pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
    try {
        await loadingTask?.destroy();
        loadingTask = pdfjs.getDocument({
            data: new Uint8Array(await file.arrayBuffer()),
        });
        pdf.value = await loadingTask.promise;
        pageNumber.value = 1;
        await renderPage();
    } catch (error) {
        console.error(`PdfPlacer: could not open PDF: ${String(error)}`);
        pdf.value = null;
        loadError.value =
            'PDF tidak dapat dibuka. Pastikan berkas tidak rusak dan tidak berpassword.';
    }
}

async function renderPage(): Promise<void> {
    if (!pdf.value || !canvas.value) {
        return;
    }
    const page = await pdf.value.getPage(pageNumber.value);
    const base = page.getViewport({ scale: 1 });
    const scale = Math.min(1.5, MAX_CSS_WIDTH / base.width);
    const vp = page.getViewport({ scale });
    const ratio = window.devicePixelRatio || 1;
    const el = canvas.value;
    el.width = Math.floor(vp.width * ratio);
    el.height = Math.floor(vp.height * ratio);
    el.style.width = `${vp.width}px`;
    el.style.height = `${vp.height}px`;

    renderTask?.cancel();
    renderTask = page.render({
        canvas: el,
        viewport: vp,
        transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : undefined,
    });
    try {
        await renderTask.promise;
    } catch {
        return; // cancelled by a newer render
    }
    viewport.value = vp;
}

/** PDF-point placement -> CSS rectangle on the rendered page. */
function toCss(
    p: Placement,
): { left: number; top: number; width: number; height: number } | null {
    const vp = viewport.value;
    if (!vp) {
        return null;
    }
    const [x1, y1] = vp.convertToViewportPoint(p.x, p.y) as [number, number];
    const [x2, y2] = vp.convertToViewportPoint(
        p.x + p.width,
        p.y + p.height,
    ) as [number, number];

    return {
        left: Math.min(x1, x2),
        top: Math.min(y1, y2),
        width: Math.abs(x2 - x1),
        height: Math.abs(y2 - y1),
    };
}

/** CSS top-left + size -> PDF-point placement on the current page. */
function fromCss(
    left: number,
    top: number,
    width: number,
    height: number,
): Placement {
    const vp = viewport.value!;
    const [ax, ay] = vp.convertToPdfPoint(left, top);
    const [bx, by] = vp.convertToPdfPoint(left + width, top + height);

    return {
        page: pageNumber.value,
        x: round(Math.min(ax, bx)),
        y: round(Math.min(ay, by)),
        width: round(Math.abs(bx - ax)),
        height: round(Math.abs(by - ay)),
    };
}

function round(n: number): number {
    return Math.round(n * 100) / 100;
}

function clamp(n: number, min: number, max: number): number {
    return Math.max(min, Math.min(max, n));
}

function placeOnCurrentPage(key: number): void {
    const vp = viewport.value;
    if (!vp) {
        return;
    }
    const size = {
        width: DEFAULT_SIZE.width * vp.scale,
        height: DEFAULT_SIZE.height * vp.scale,
    };
    // Stagger new boxes so they do not land on top of each other.
    const index = props.signers.findIndex((s) => s.key === key);
    const left = clamp(
        24 + index * (size.width + 12),
        0,
        vp.width - size.width,
    );
    const top = clamp(vp.height - size.height - 24, 0, vp.height - size.height);
    placements.value = {
        ...placements.value,
        [key]: fromCss(left, top, size.width, size.height),
    };
}

function remove(key: number): void {
    const next = { ...placements.value };
    delete next[key];
    placements.value = next;
}

function onPointerDown(event: PointerEvent, key: number): void {
    const box = toCss(placements.value[key]);
    const host = (
        event.currentTarget as HTMLElement
    ).parentElement!.getBoundingClientRect();
    if (!box) {
        return;
    }
    dragging.value = key;
    dragOffset = {
        x: event.clientX - host.left - box.left,
        y: event.clientY - host.top - box.top,
    };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onPointerMove(event: PointerEvent, key: number): void {
    if (dragging.value !== key || !viewport.value) {
        return;
    }
    const vp = viewport.value;
    const box = toCss(placements.value[key])!;
    const host = (
        event.currentTarget as HTMLElement
    ).parentElement!.getBoundingClientRect();
    const left = clamp(
        event.clientX - host.left - dragOffset.x,
        0,
        vp.width - box.width,
    );
    const top = clamp(
        event.clientY - host.top - dragOffset.y,
        0,
        vp.height - box.height,
    );
    placements.value = {
        ...placements.value,
        [key]: fromCss(left, top, box.width, box.height),
    };
}

/** Keyboard alternative to dragging: arrow keys move 5 pt (Shift: 20 pt). */
function onKeyDown(event: KeyboardEvent, key: number): void {
    const step = event.shiftKey ? 20 : 5;
    const delta: Record<string, [number, number]> = {
        ArrowLeft: [-step, 0],
        ArrowRight: [step, 0],
        ArrowUp: [0, -step],
        ArrowDown: [0, step],
    };
    const d = delta[event.key];
    const vp = viewport.value;
    const box = toCss(placements.value[key]);
    if (!d || !vp || !box) {
        return;
    }
    event.preventDefault();
    const left = clamp(box.left + d[0] * vp.scale, 0, vp.width - box.width);
    const top = clamp(box.top + d[1] * vp.scale, 0, vp.height - box.height);
    placements.value = {
        ...placements.value,
        [key]: fromCss(left, top, box.width, box.height),
    };
}

const boxesOnPage = computed(() =>
    props.signers
        .map((s, i) => ({
            signer: s,
            order: i + 1,
            placement: placements.value[s.key],
        }))
        .filter((b) => b.placement && b.placement.page === pageNumber.value),
);

// The canvas only exists after mount, so the first load must wait for it.
onMounted(() => void loadPdf(props.file));
watch(
    () => props.file,
    (f) => void loadPdf(f),
);
watch(pageNumber, () => void renderPage());
onBeforeUnmount(() => {
    renderTask?.cancel();
    void loadingTask?.destroy();
});
</script>

<template>
    <div class="space-y-4">
        <p v-if="loadError" class="text-sm text-destructive" role="alert">
            {{ loadError }}
        </p>

        <div v-else class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        :disabled="pageNumber <= 1"
                        aria-label="Halaman sebelumnya"
                        @click="pageNumber--"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                    <span class="text-sm tabular-nums" aria-live="polite">
                        Halaman {{ pageNumber }} dari {{ pageCount || '…' }}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        :disabled="pageNumber >= pageCount"
                        aria-label="Halaman berikutnya"
                        @click="pageNumber++"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    Geser kotak dengan mouse, atau pilih kotak lalu gunakan
                    tombol panah.
                </p>
            </div>

            <ul class="flex flex-wrap gap-2">
                <li
                    v-for="(s, i) in signers"
                    :key="s.key"
                    class="flex items-center gap-2 rounded-md border px-2 py-1 text-sm"
                >
                    <span class="font-medium tabular-nums">{{ i + 1 }}.</span>
                    <span>{{ s.label }}</span>
                    <template v-if="placements[s.key]">
                        <span class="text-muted-foreground">
                            (hal. {{ placements[s.key].page }})
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="placeOnCurrentPage(s.key)"
                        >
                            Pindah ke hal. ini
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="remove(s.key)"
                        >
                            Hapus
                        </Button>
                    </template>
                    <Button
                        v-else
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="!viewport"
                        @click="placeOnCurrentPage(s.key)"
                    >
                        Taruh di halaman ini
                    </Button>
                </li>
            </ul>

            <div class="overflow-x-auto rounded-md border bg-muted/40 p-2">
                <div class="relative mx-auto w-fit touch-none select-none">
                    <canvas ref="canvas" class="block bg-white shadow-sm" />
                    <template v-for="b in boxesOnPage" :key="b.signer.key">
                        <div
                            v-if="toCss(b.placement)"
                            role="button"
                            tabindex="0"
                            :aria-label="`Kotak QR penanda tangan ${b.order}: ${b.signer.label}. Gunakan tombol panah untuk memindahkan.`"
                            class="absolute flex cursor-move flex-col items-center justify-center gap-1 rounded-sm border-2 border-primary bg-primary/10 text-xs font-medium text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="{
                                'ring-2 ring-ring': dragging === b.signer.key,
                            }"
                            :style="{
                                left: `${toCss(b.placement)!.left}px`,
                                top: `${toCss(b.placement)!.top}px`,
                                width: `${toCss(b.placement)!.width}px`,
                                height: `${toCss(b.placement)!.height}px`,
                            }"
                            @pointerdown="onPointerDown($event, b.signer.key)"
                            @pointermove="onPointerMove($event, b.signer.key)"
                            @pointerup="dragging = null"
                            @pointercancel="dragging = null"
                            @keydown="onKeyDown($event, b.signer.key)"
                        >
                            <span class="text-base tabular-nums"
                                >QR {{ b.order }}</span
                            >
                            <span class="max-w-full truncate px-1">{{
                                b.signer.label
                            }}</span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
