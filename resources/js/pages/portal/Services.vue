<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CalendarDays, ExternalLink, Link2, RefreshCw } from '@lucide/vue';
import StatusPill from '@/components/billing/StatusPill.vue';
import { cycleLabels, fmtDate } from '@/lib/billingUi';

type Related = { id: number; name: string; description: string | null; billing_cycle: string; start_date: string; end_date: string | null; auto_renew: boolean; status: string };
type Svc = Related & { next_charge_on: string | null; proposal: { number: string; title: string; url: string } | null; related: Related[] };

defineProps<{ company: { id: number; name: string }; services: Svc[] }>();
</script>

<template>
    <Head title="Servicios" />

    <div class="flex flex-col gap-5">
        <div><h1 class="text-2xl font-semibold tracking-tight">Servicios contratados</h1><p class="mt-1 text-sm text-muted-foreground">Lo que {{ company.name }} tiene contratado con Vivienda en Regla: vigencia, renovación y servicios asociados.</p></div>

        <p v-if="!services.length" class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground">Aún no tienes servicios contratados.</p>

        <article v-for="s in services" :key="s.id" class="overflow-hidden rounded-2xl border bg-card shadow-sm shadow-black/[0.03]">
            <div class="grid gap-3 p-5">
                <div class="flex flex-wrap items-start justify-between gap-2"><h2 class="text-lg font-semibold">{{ s.name }}</h2><StatusPill kind="service" :status="s.status" /></div>
                <p v-if="s.description" class="text-sm whitespace-pre-line text-muted-foreground">{{ s.description }}</p>
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="flex items-center gap-1 text-xs text-muted-foreground"><CalendarDays class="size-3" /> Vigencia</dt><dd class="mt-0.5">{{ fmtDate(s.start_date) }} → {{ s.end_date ? fmtDate(s.end_date) : 'sin término' }}</dd></div>
                    <div><dt class="flex items-center gap-1 text-xs text-muted-foreground"><RefreshCw class="size-3" /> Renovación</dt><dd class="mt-0.5">{{ s.auto_renew ? 'Automática' : 'No se renueva automáticamente' }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Modalidad de cobro</dt><dd class="mt-0.5">{{ cycleLabels[s.billing_cycle] }}<span v-if="s.next_charge_on"> · próximo cobro {{ fmtDate(s.next_charge_on) }}</span></dd></div>
                </dl>
                <a v-if="s.proposal" :href="s.proposal.url" target="_blank" rel="noopener" class="inline-flex w-fit items-center gap-1.5 text-sm text-primary hover:underline"><ExternalLink class="size-3.5" /> Propuesta {{ s.proposal.number }} · {{ s.proposal.title }}</a>
            </div>
            <div v-if="s.related.length" class="border-t bg-muted/30 px-5 py-3">
                <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"><Link2 class="size-3.5" /> Servicios asociados</p>
                <ul class="grid gap-2">
                    <li v-for="r in s.related" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-card p-3 text-sm">
                        <div class="min-w-0"><p class="font-medium">{{ r.name }}</p><p class="text-xs text-muted-foreground">{{ cycleLabels[r.billing_cycle] }} · {{ fmtDate(r.start_date) }} → {{ r.end_date ? fmtDate(r.end_date) : 'sin término' }}{{ r.auto_renew ? ' · renovación automática' : '' }}</p></div>
                        <StatusPill kind="service" :status="r.status" />
                    </li>
                </ul>
            </div>
        </article>
    </div>
</template>
