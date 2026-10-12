<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { fmtDate, money } from '@/lib/billingUi';

defineProps<{
    company: { id: number; name: string };
    proposals: { id: number; number: string; title: string; status: string; status_label: string; status_color: string; currency: string; total_net: number; sent_at: string | null; valid_until: string | null; url: string }[];
}>();
</script>

<template>
    <Head title="Propuestas" />

    <div class="flex flex-col gap-5">
        <div><h1 class="text-2xl font-semibold tracking-tight">Propuestas</h1><p class="mt-1 text-sm text-muted-foreground">Las propuestas comerciales que INTEGRALEADS preparó para {{ company.name }}.</p></div>

        <p v-if="!proposals.length" class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground">Aún no tienes propuestas.</p>
        <div class="grid gap-3">
            <div v-for="p in proposals" :key="p.id" class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm shadow-black/[0.03] sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2"><p class="font-semibold">{{ p.title }}</p><span class="rounded-full px-2.5 py-0.5 text-xs font-medium text-white" :style="{ backgroundColor: p.status_color }">{{ p.status_label }}</span></div>
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ p.number }}<span v-if="p.sent_at"> · enviada {{ fmtDate(p.sent_at) }}</span><span v-if="p.valid_until"> · válida hasta {{ fmtDate(p.valid_until) }}</span></p>
                </div>
                <div class="flex items-center justify-between gap-4 sm:flex-col sm:items-end sm:gap-2">
                    <p class="text-lg font-semibold tabular-nums">{{ money(p.total_net, p.currency) }} <span class="text-xs font-normal text-muted-foreground">+ IVA</span></p>
                    <Button size="sm" variant="outline" as-child><a :href="p.url" target="_blank" rel="noopener"><ExternalLink /> Ver propuesta</a></Button>
                </div>
            </div>
        </div>
    </div>
</template>
