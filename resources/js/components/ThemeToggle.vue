<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

// Un clic alterna entre modo claro y oscuro (la opción «Sistema» sigue en Ajustes → Apariencia).
const { resolvedAppearance, updateAppearance } = useAppearance();
const toggle = () => updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
</script>

<template>
    <Button
        type="button"
        variant="ghost"
        size="icon"
        class="shrink-0"
        :title="resolvedAppearance === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
        :aria-label="resolvedAppearance === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
        @click="toggle"
    >
        <Sun v-if="resolvedAppearance === 'dark'" class="size-[1.15rem]" />
        <Moon v-else class="size-[1.15rem]" />
    </Button>
</template>
