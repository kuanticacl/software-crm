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
    <!-- Logo oficial de MecaniQ: texto oscuro en modo claro, texto blanco en modo oscuro -->
    <span class="inline-flex" :class="$props.class" v-bind="$attrs">
        <img src="/brand/mecaniq-logo.png" alt="MecaniQ" class="h-full w-auto dark:hidden" />
        <img src="/brand/mecaniq-logo-white.png" alt="MecaniQ" class="hidden h-full w-auto dark:block" />
    </span>
</template>
