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
    <!-- Logo de Vivienda en Regla: isotipo oro rosa + wordmark (el texto toma el color actual; tipografía Cormorant Garamond) -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 252 44"
        role="img"
        aria-label="Vivienda en Regla"
        :class="$props.class"
        v-bind="$attrs"
    >
        <defs>
            <linearGradient id="vr-gold" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#D9A89E" />
                <stop offset=".55" stop-color="#B76E79" />
                <stop offset="1" stop-color="#9C5560" />
            </linearGradient>
        </defs>
        <g transform="translate(0 2) scale(.62)" fill="none" stroke="url(#vr-gold)" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 31 32 9l24 22" />
            <path d="M14 27v26h36V27" />
            <path d="M24 53V40l8 9 8-9v13" />
        </g>
        <text x="46" y="24.5" font-family="'Cormorant Garamond', Georgia, serif" font-weight="600" font-size="25" letter-spacing=".2" fill="currentColor">Vivienda en Regla</text>
        <text x="47" y="39" font-family="'Jost', ui-sans-serif, sans-serif" font-weight="500" font-size="6.2" letter-spacing="2.3" fill="#B76E79">ARQUITECTURA · REGULARIZACIONES</text>
    </svg>
</template>
