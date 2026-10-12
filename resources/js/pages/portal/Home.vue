<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CircleAlert, FileText, Package, Receipt } from '@lucide/vue';
import BankAccountsCard from '@/components/portal/BankAccountsCard.vue';
import type { PortalBank } from '@/components/portal/BankAccountsCard.vue';
import InvoiceCard from '@/components/portal/InvoiceCard.vue';
import type { PortalInvoice } from '@/components/portal/InvoiceCard.vue';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/billingUi';

const props = defineProps<{
    company: { id: number; name: string };
    summary: { active_services: number; pending_invoices: number; overdue_invoices: number; pending_total_clp: number; proposals: number };
    next_invoice: PortalInvoice | null;
    bank: PortalBank | null;
    recent_invoices: PortalInvoice[];
}>();

const cards = [
    { label: 'Servicios activos', value: () => String(props.summary.active_services), icon: Package, href: '/portal/servicios', color: '#A9606B' },
    { label: 'Cobros por pagar', value: () => String(props.summary.pending_invoices), icon: Receipt, href: '/portal/facturas', color: '#4A8CFF', note: () => (props.summary.pending_invoices ? `Total ${money(props.summary.pending_total_clp)}` : 'Todo al día') },
    { label: 'Propuestas', value: () => String(props.summary.proposals), icon: FileText, href: '/portal/propuestas', color: '#FFA165' },
];
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Hola, bienvenido/a</h1>
            <p class="mt-1 text-sm text-muted-foreground">Estás viendo la información de <strong>{{ company.name }}</strong>.</p>
        </div>

        <div v-if="summary.overdue_invoices" class="flex items-start gap-3 rounded-2xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-700 dark:text-red-300">
            <CircleAlert class="mt-0.5 size-5 shrink-0" />
            <p>Tienes <strong>{{ summary.overdue_invoices }}</strong> cobro{{ summary.overdue_invoices === 1 ? '' : 's' }} vencido{{ summary.overdue_invoices === 1 ? '' : 's' }}. <Link href="/portal/facturas" class="font-semibold underline">Revisar ahora</Link></p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <Link v-for="c in cards" :key="c.label" :href="c.href" class="flex items-center gap-4 rounded-2xl border bg-card p-4 shadow-sm shadow-black/[0.03] transition-shadow hover:shadow-md">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl" :style="{ backgroundColor: c.color + '1f', color: c.color }"><component :is="c.icon" class="size-5" /></div>
                <div class="min-w-0"><p class="text-xs text-muted-foreground">{{ c.label }}</p><p class="text-2xl font-semibold tabular-nums">{{ c.value() }}</p><p v-if="c.note" class="text-xs text-muted-foreground">{{ c.note() }}</p></div>
            </Link>
        </div>

        <section v-if="next_invoice" class="grid gap-3">
            <h2 class="font-semibold">Próximo cobro por pagar</h2>
            <InvoiceCard :invoice="next_invoice" />
        </section>

        <BankAccountsCard v-if="bank" :bank="bank" />

        <section class="grid gap-3">
            <div class="flex items-center justify-between"><h2 class="font-semibold">Últimos cobros</h2><Button variant="ghost" size="sm" as-child><Link href="/portal/facturas">Ver todas <ArrowRight /></Link></Button></div>
            <p v-if="!recent_invoices.length" class="rounded-2xl border border-dashed bg-card p-6 text-center text-sm text-muted-foreground">Aún no tienes cobros.</p>
            <InvoiceCard v-for="i in recent_invoices" :key="i.id" :invoice="i" />
        </section>
    </div>
</template>
