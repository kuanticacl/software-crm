<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <!-- Logo oficial de Vivienda en Regla (PNG con transparencia usado como máscara: toma el color de texto actual y cambia solo en modo oscuro) -->
    <span
        role="img"
        aria-label="Vivienda en Regla"
        class="inline-block bg-current"
        :class="$props.class"
        style="aspect-ratio: 742 / 199; -webkit-mask: url('/brand/vivienda-logo-white.png') no-repeat center / contain; mask: url('/brand/vivienda-logo-white.png') no-repeat center / contain"
        v-bind="$attrs"
    />
</template>
