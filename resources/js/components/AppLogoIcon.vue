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
    <!-- Isotipo de Vivienda en Regla: casa de línea fina con una «V» (toma el color actual) -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" :class="className" v-bind="$attrs" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
        <path d="M8 31 32 9l24 22" />
        <path d="M14 27v26h36V27" />
        <path d="M24 53V40l8 9 8-9v13" />
    </svg>
</template>
