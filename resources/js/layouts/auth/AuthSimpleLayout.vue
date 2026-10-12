<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import BrandLogo from '@/components/BrandLogo.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-gradient-to-b from-[#f6efeb] to-white p-6 md:p-10 dark:from-[#171112] dark:to-[#1d1718]"
    >
        <!-- Isotipo de fondo -->
        <AppLogoIcon
            class="pointer-events-none absolute -top-24 -right-24 size-[34rem] text-black/[0.035] dark:text-white/[0.04]"
        />
        <div
            class="pointer-events-none absolute -bottom-40 -left-40 size-[28rem] rounded-full bg-primary/10 blur-3xl"
        />

        <div class="relative w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-6">
                    <Link :href="home()" class="flex items-center">
                        <BrandLogo class="h-12 w-auto text-foreground" />
                        <span class="sr-only">Vivienda en Regla</span>
                    </Link>
                    <div class="space-y-2 text-center">
                        <h1 class="text-2xl font-semibold text-primary">
                            {{ title }}
                        </h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <div
                    class="rounded-2xl border bg-card p-6 shadow-sm shadow-black/5"
                >
                    <slot />
                </div>
                <p class="text-center text-xs text-muted-foreground">
                    Arquitectura y regularizaciones · CRM interno
                </p>
            </div>
        </div>
    </div>
</template>
