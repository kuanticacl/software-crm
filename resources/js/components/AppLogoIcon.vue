<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Isotipo oficial «Q» de MecaniQ: PNG como máscara, toma el color actual -->
    <span
        role="img"
        aria-label="MecaniQ"
        class="inline-block bg-current"
        :class="className"
        style="aspect-ratio: 140 / 92; -webkit-mask: url('/brand/mecaniq-mark-mask.png') no-repeat center / contain; mask: url('/brand/mecaniq-mark-mask.png') no-repeat center / contain"
        v-bind="$attrs"
    />
</template>
