<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { DocumentStatusValue } from '@/types/tte';

const props = defineProps<{ value: DocumentStatusValue; label: string }>();

// Status is also carried by the text, never by colour alone.
const variant = computed(() => {
    switch (props.value) {
        case 'completed':
            return 'default' as const;
        case 'revoked':
        case 'rejected':
            return 'destructive' as const;
        default:
            return 'secondary' as const;
    }
});
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
