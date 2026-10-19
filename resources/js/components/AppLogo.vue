<script setup lang="ts">
import BrandLogo from '@/components/BrandLogo.vue';
</script>

<template>
    <!-- Expandido: logo completo. Colapsado: isotipo Q -->
    <span class="flex items-center group-data-[collapsible=icon]:hidden">
        <BrandLogo class="h-8 shrink-0" />
    </span>
    <span
        class="hidden items-center justify-center group-data-[collapsible=icon]:flex"
    >
        <img src="/brand/mecaniq-mark.png" alt="MecaniQ" class="h-6 w-auto" />
    </span>
    <span
        class="ml-auto rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-primary uppercase group-data-[collapsible=icon]:hidden"
    >
        CRM
    </span>
</template>
