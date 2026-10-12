<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
</script>

<template>
    <!-- Expandido: logo completo. Colapsado: isotipo -->
    <span class="flex items-center group-data-[collapsible=icon]:hidden">
        <BrandLogo class="h-7 shrink-0 text-foreground" />
    </span>
    <span
        class="hidden items-center justify-center group-data-[collapsible=icon]:flex"
    >
        <span class="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary"><AppLogoIcon class="size-6" /></span>
    </span>
</template>
