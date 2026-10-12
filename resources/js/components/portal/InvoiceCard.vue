<script setup lang="ts">
import { Download, ExternalLink } from '@lucide/vue';
import StatusPill from '@/components/billing/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { fmtDate, money } from '@/lib/billingUi';

export type PortalInvoice = {
    id: number; number: string | null; concept: string; period_start: string | null; period_end: string | null; issue_date: string | null; due_date: string;
    currency: string; amount_total: number; total_clp: number | null; document_label: string; is_boleta: boolean; amount_net: number; tax_rate: number; retention_rate: number; retention_amount: number; retention_by_client: boolean; status: string; paid_at: string | null; payment_link: string | null; has_pdf: boolean;
};
defineProps<{ invoice: PortalInvoice }>();
</script>

<template>
    <div class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm shadow-black/[0.03] sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <p class="font-semibold">{{ invoice.number ? `${invoice.document_label} N° ${invoice.number}` : invoice.document_label }}</p>
                <StatusPill kind="invoice" :status="invoice.status" />
            </div>
            <p class="mt-0.5 text-sm text-muted-foreground">{{ invoice.concept }}</p>
            <p class="mt-1 text-xs text-muted-foreground">
                <span v-if="invoice.issue_date">Emitida {{ fmtDate(invoice.issue_date) }} · </span>
                <span v-if="invoice.status === 'paid'">Pagada {{ fmtDate(invoice.paid_at) }}</span>
                <span v-else :class="invoice.status === 'overdue' ? 'font-medium text-red-600 dark:text-red-400' : ''">Vence {{ fmtDate(invoice.due_date) }}</span>
            </p>
        </div>
        <div class="flex items-center justify-between gap-4 sm:flex-col sm:items-end sm:gap-2">
            <div class="sm:text-right">
                <p class="text-lg font-semibold tabular-nums">{{ money(invoice.amount_total, invoice.currency) }}</p>
                <p v-if="invoice.currency === 'UF' && invoice.total_clp" class="text-xs text-muted-foreground">≈ {{ money(invoice.total_clp) }}</p>
                <p v-if="invoice.is_boleta && invoice.retention_by_client && invoice.retention_amount > 0" class="text-xs text-muted-foreground">Honorarios {{ money(invoice.amount_net, invoice.currency) }} − retención {{ invoice.retention_rate }}% ({{ money(invoice.retention_amount, invoice.currency) }})</p>
            </div>
            <div class="flex gap-2">
                <Button v-if="invoice.payment_link && invoice.status !== 'paid'" size="sm" as-child><a :href="invoice.payment_link" target="_blank" rel="noopener"><ExternalLink /> Pagar</a></Button>
                <Button v-if="invoice.has_pdf" size="sm" variant="outline" as-child><a :href="`/portal/facturas/${invoice.id}/pdf`"><Download /> PDF</a></Button>
            </div>
        </div>
    </div>
</template>
