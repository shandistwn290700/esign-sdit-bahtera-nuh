<script setup lang="ts">
import { useId } from 'vue';

/**
 * Card with the Material Dashboard header strip floating over its top edge.
 * tone: "neutral" for structure, "primary" (green) only for the screen's key action,
 * "danger" only for destructive sections.
 */
type Tone = 'neutral' | 'primary' | 'danger';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        tone?: Tone;
        /** Remove inner padding, e.g. for edge-to-edge tables. */
        flush?: boolean;
    }>(),
    { tone: 'neutral', flush: false },
);

const headingId = useId();
const toneClass: Record<Tone, string> = {
    neutral: 'bg-(--strip) text-(--strip-foreground)',
    primary: 'bg-primary text-primary-foreground',
    danger: 'bg-destructive text-white',
};
</script>

<template>
    <section
        :aria-labelledby="headingId"
        class="mt-5 rounded-xl bg-card text-card-foreground shadow-card"
    >
        <div
            :class="[
                'mx-4 -mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg px-4 py-3 shadow-sm',
                toneClass[tone],
            ]"
        >
            <div class="min-w-0">
                <h2 :id="headingId" class="text-base font-semibold">
                    {{ title }}
                </h2>
                <p v-if="description" class="text-sm opacity-85">
                    {{ description }}
                </p>
            </div>
            <slot name="actions" />
        </div>
        <div :class="flush ? 'pt-2 pb-2' : 'space-y-4 px-4 pt-5 pb-5 sm:px-6'">
            <slot />
        </div>
    </section>
</template>
