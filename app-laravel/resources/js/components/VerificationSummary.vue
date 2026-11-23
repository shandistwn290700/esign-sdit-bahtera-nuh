<script setup lang="ts">
import { CircleAlert, CircleCheck, Clock, Ban } from '@lucide/vue';
import { computed } from 'vue';
import { formatDateTime } from '@/lib/format';
import type { PublicVerification } from '@/types/tte';

const props = defineProps<{ verification: PublicVerification }>();

const tone = computed(() => {
    switch (props.verification.status.value) {
        case 'valid':
            return {
                icon: CircleCheck,
                box: 'border-green-600/40 bg-green-50 text-green-900 dark:bg-green-950 dark:text-green-100',
                text: 'Dokumen ini ditandatangani secara elektronik dan terdaftar di sistem kami.',
            };
        case 'revoked':
            return {
                icon: Ban,
                box: 'border-red-600/40 bg-red-50 text-red-900 dark:bg-red-950 dark:text-red-100',
                text: 'Dokumen ini telah dicabut oleh penerbitnya dan tidak berlaku lagi.',
            };
        case 'rejected':
            return {
                icon: CircleAlert,
                box: 'border-red-600/40 bg-red-50 text-red-900 dark:bg-red-950 dark:text-red-100',
                text: 'Dokumen ini ditolak dan tidak pernah selesai ditandatangani.',
            };
        default:
            return {
                icon: Clock,
                box: 'border-amber-600/40 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
                text: 'Dokumen ini belum selesai ditandatangani oleh semua pihak.',
            };
    }
});
</script>

<template>
    <div class="space-y-6">
        <div
            class="flex items-start gap-4 rounded-lg border-2 p-5"
            :class="tone.box"
            role="status"
        >
            <component
                :is="tone.icon"
                class="mt-0.5 size-8 shrink-0"
                aria-hidden="true"
            />
            <div>
                <p class="text-2xl font-semibold tracking-wide">
                    {{ verification.status.label }}
                </p>
                <p class="mt-1 text-sm">{{ tone.text }}</p>
                <p v-if="verification.revoked_at" class="mt-1 text-sm">
                    Dicabut {{ formatDateTime(verification.revoked_at) }}
                </p>
            </div>
        </div>

        <dl
            v-if="verification.title || verification.number"
            class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]"
        >
            <template v-if="verification.title">
                <dt class="text-muted-foreground">Judul</dt>
                <dd class="font-medium">{{ verification.title }}</dd>
            </template>
            <template v-if="verification.number">
                <dt class="text-muted-foreground">Nomor</dt>
                <dd>{{ verification.number }}</dd>
            </template>
        </dl>

        <section aria-labelledby="sig-heading" class="space-y-2">
            <h2 id="sig-heading" class="text-sm font-semibold">
                Ditandatangani oleh
                <span class="font-normal text-muted-foreground tabular-nums">
                    ({{ verification.signatures.length }} dari
                    {{ verification.signers_total }})
                </span>
            </h2>
            <p
                v-if="!verification.signatures.length"
                class="text-sm text-muted-foreground"
            >
                Belum ada tanda tangan.
            </p>
            <ul v-else class="divide-y rounded-md border text-sm">
                <li
                    v-for="(s, i) in verification.signatures"
                    :key="i"
                    class="flex flex-wrap justify-between gap-2 px-4 py-3"
                >
                    <div>
                        <p class="font-medium">{{ s.name }}</p>
                        <p class="text-muted-foreground">{{ s.position }}</p>
                    </div>
                    <p class="text-muted-foreground">
                        {{ formatDateTime(s.signed_at) }}
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
