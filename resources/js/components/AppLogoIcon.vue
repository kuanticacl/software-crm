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
    <!-- Isotipo oficial (casa con escuadra y lápiz): PNG como máscara, toma el color actual -->
    <span
        role="img"
        aria-label="Vivienda en Regla"
        class="inline-block bg-current"
        :class="className"
        style="aspect-ratio: 207 / 195; -webkit-mask: url('/brand/vivienda-mark-white.png') no-repeat center / contain; mask: url('/brand/vivienda-mark-white.png') no-repeat center / contain"
        v-bind="$attrs"
    />
</template>
