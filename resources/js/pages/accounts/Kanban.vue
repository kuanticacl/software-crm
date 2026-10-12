<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarClock, CircleAlert, Layers, Plus, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import PageHeader from '@/components/PageHeader.vue';
import InvoiceFormDialog from '@/components/billing/InvoiceFormDialog.vue';
import InvoicePayDialog from '@/components/billing/InvoicePayDialog.vue';
import InvoicePdfDialog from '@/components/billing/InvoicePdfDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { fmtDate, money } from '@/lib/billingUi';
import { cn } from '@/lib/utils';
import type { InvoiceRow } from '@/types/billing';

type Card = {
    column: string; id: number; name: string; tax_id: string | null; mrr: number; services_active: number; services: string[]; pending_clp: number; overdue_clp: number;
    overdue_days: number | null; next_due: string | null; to_issue: number; renewal: { name: string; date: string; days: number } | null;
};
type Col = { key: string; name: string; color: string; hint?: string; total: number; mrr?: number; amount: number; cards: Card[] };
type InvCol = { key: string; name: string; color: string; total: number; amount: number; cards: InvoiceRow[] };

const props = defineProps<{
    view: 'accounts' | 'invoices';
    q: string;
    accounts: { columns: Col[]; totals: { accounts: number; mrr: number } } | null;
    pipeline: InvCol[] | null;
    can: { billing: boolean; manage: boolean; pay: boolean };
    lookups: { clients: { id: number; name: string }[]; services: { id: number; name: string; client_id: number; currency: string; price: number }[]; taxRate: number } | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Kanban de cuentas', href: '/accounts/kanban' }] } });

const search = ref(props.q);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/accounts/kanban', { view: props.view, q: search.value || undefined }, { preserveState: true, preserveScroll: true, replace: true, only: ['accounts', 'pipeline', 'q', 'view'] }), 300);
});
const setView = (v: 'accounts' | 'invoices') => router.get('/accounts/kanban', { view: v, q: search.value || undefined }, { preserveState: true, replace: true });

const daysTxt = (n: number) => (n === 0 ? 'hoy' : n === 1 ? 'mañana' : n < 0 ? `hace ${Math.abs(n)} d` : `en ${n} d`);

// ---- cobros: arrastrar para cambiar de estado ----
const cols = ref<InvCol[]>([]);
watch(() => props.pipeline, (p) => (cols.value = p ? p.map((c) => ({ ...c, cards: [...c.cards] })) : []), { immediate: true, deep: true });
const reload = () => router.reload({ only: ['pipeline'] });

const payFor = ref<InvoiceRow | null>(null);
const pdfFor = ref<InvoiceRow | null>(null);
const newOpen = ref(false);

type Change = { added?: { element: InvoiceRow } };
const onChange = (target: string, ch: Change) => {
    const inv = ch.added?.element;
    if (!inv) return;
    const from = inv.display_status === 'issued' ? 'issued' : inv.display_status; // scheduled | issued | overdue | paid | cancelled
    if (target === 'paid' && ['issued', 'overdue', 'scheduled'].includes(from) && props.can.pay) { payFor.value = inv; return; }
    if (target === 'issued' && from === 'scheduled' && props.can.manage) {
        if (inv.has_pdf) router.post(`/billing/invoices/${inv.id}/issue`, {}, { preserveScroll: true, onFinish: reload });
        else pdfFor.value = inv;
        return;
    }
    if (['issued', 'overdue'].includes(target) && from === 'paid' && props.can.pay) { router.post(`/billing/invoices/${inv.id}/reopen`, {}, { preserveScroll: true, onFinish: reload }); return; }
    reload(); // movimiento no permitido: la tarjeta vuelve a su columna
};

const totalAmount = computed(() => (props.pipeline ?? []).filter((c) => c.key !== 'paid').reduce((s, c) => s + c.amount, 0));
</script>

<template>
    <Head title="Kanban de cuentas" />

    <div class="flex h-[calc(100svh-4rem)] min-h-0 flex-col gap-4 p-4 md:p-6">
        <PageHeader :title="view === 'accounts' ? 'Kanban de cuentas' : 'Kanban de cobros'" :description="view === 'accounts' ? 'Cada cliente con servicios, ordenada sola según su situación: mora, por facturar, por cobrar, renovación…' : 'Cada cobro según su estado. Arrastra una factura a «Pagadas» para registrar el pago, o de «Por emitir» a «Por pagar» para emitirla.'">
            <template #actions>
                <div class="inline-flex rounded-xl border bg-card p-0.5">
                    <button type="button" :class="cn('rounded-[10px] px-3.5 py-1.5 text-sm font-medium', view === 'accounts' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground')" @click="setView('accounts')">Cuentas</button>
                    <button v-if="can.billing" type="button" :class="cn('rounded-[10px] px-3.5 py-1.5 text-sm font-medium', view === 'invoices' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground')" @click="setView('invoices')">Cobros</button>
                </div>
                <Button v-if="view === 'invoices' && can.manage" @click="newOpen = true"><Plus /> Nuevo cobro</Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full sm:w-80"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="search" :placeholder="view === 'accounts' ? 'Buscar cliente o RUT…' : 'Buscar factura, concepto o cliente…'" class="pl-9" /></div>
            <p v-if="view === 'accounts' && accounts" class="text-sm text-muted-foreground"><strong class="text-foreground">{{ accounts.totals.accounts }}</strong> cuentas · ingreso recurrente <strong class="text-foreground">{{ money(accounts.totals.mrr) }}</strong>/mes</p>
            <p v-else-if="pipeline" class="text-sm text-muted-foreground">Pendiente de cobro <strong class="text-foreground">{{ money(totalAmount) }}</strong></p>
        </div>

        <!-- Cuentas -->
        <div v-if="view === 'accounts' && accounts" class="flex min-h-0 flex-1 snap-x gap-3 overflow-x-auto pb-2">
            <section v-for="col in accounts.columns" :key="col.key" class="flex h-full min-h-64 w-[19.5rem] shrink-0 snap-start flex-col rounded-2xl bg-muted/60 ring-1 ring-border/60">
                <header class="p-3 pb-2">
                    <div class="flex items-center gap-2"><span class="size-3 rounded-full" :style="{ backgroundColor: col.color }" /><h2 class="font-semibold">{{ col.name }}</h2><span class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold text-white" :style="{ backgroundColor: col.color }">{{ col.total }}</span></div>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ col.hint }}</p>
                    <p v-if="col.total && (col.mrr || col.amount)" class="mt-1 text-[11px] text-muted-foreground"><span v-if="col.mrr">{{ money(col.mrr) }}/mes</span><span v-if="col.mrr && col.amount"> · </span><span v-if="col.amount">{{ money(col.amount) }} {{ col.key === 'overdue' ? 'vencido' : 'por cobrar' }}</span></p>
                </header>
                <div class="grid min-h-0 flex-1 content-start gap-2 overflow-y-auto p-2 pt-1">
                    <p v-if="!col.cards.length" class="rounded-xl border border-dashed p-4 text-center text-xs text-muted-foreground">Sin cuentas</p>
                    <Link v-for="c in col.cards" :key="c.id" :href="`/clients/${c.id}`" class="grid gap-2 rounded-xl border bg-card p-3 text-sm shadow-sm transition hover:shadow-md">
                        <div class="min-w-0"><p class="truncate font-semibold">{{ c.name }}</p><p v-if="c.tax_id" class="text-xs text-muted-foreground">{{ c.tax_id }}</p></div>
                        <p v-if="c.services.length" class="flex items-start gap-1.5 text-xs text-muted-foreground"><Layers class="mt-0.5 size-3.5 shrink-0" /><span class="line-clamp-2">{{ c.services.join(' · ') }}<span v-if="c.services_active > c.services.length"> +{{ c.services_active - c.services.length }}</span></span></p>
                        <div class="flex flex-wrap gap-1.5 text-[11px] font-medium">
                            <span v-if="c.mrr" class="rounded-full bg-brand-green/10 px-2 py-0.5 text-brand-green">{{ money(c.mrr) }}/mes</span>
                            <span v-if="c.overdue_clp" class="inline-flex items-center gap-1 rounded-full bg-red-500/10 px-2 py-0.5 text-red-600 dark:text-red-400"><CircleAlert class="size-3" />{{ money(c.overdue_clp) }} · {{ c.overdue_days }} d</span>
                            <span v-else-if="c.pending_clp" class="rounded-full bg-sky-500/10 px-2 py-0.5 text-sky-600 dark:text-sky-400">{{ money(c.pending_clp) }}<template v-if="c.next_due"> · vence {{ daysTxt(Math.round((new Date(c.next_due + 'T12:00:00').getTime() - Date.now()) / 86400000)) }}</template></span>
                            <span v-if="c.to_issue" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-amber-600 dark:text-amber-400">{{ c.to_issue }} por facturar</span>
                            <span v-if="c.renewal" class="inline-flex items-center gap-1 rounded-full bg-purple-500/10 px-2 py-0.5 text-purple-600 dark:text-purple-400"><CalendarClock class="size-3" />{{ fmtDate(c.renewal.date) }}</span>
                        </div>
                    </Link>
                </div>
            </section>
        </div>

        <!-- Cobros -->
        <div v-else-if="view === 'invoices'" class="flex min-h-0 flex-1 snap-x gap-3 overflow-x-auto pb-2">
            <section v-for="col in cols" :key="col.key" class="flex h-full min-h-64 w-[19.5rem] shrink-0 snap-start flex-col rounded-2xl bg-muted/60 ring-1 ring-border/60">
                <header class="p-3 pb-2"><div class="flex items-center gap-2"><span class="size-3 rounded-full" :style="{ backgroundColor: col.color }" /><h2 class="font-semibold">{{ col.name }}</h2><span class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-semibold text-white" :style="{ backgroundColor: col.color }">{{ col.total }}</span></div><p class="mt-1 text-[11px] text-muted-foreground">{{ money(col.amount) }}</p></header>
                <draggable v-model="col.cards" group="invoices" item-key="id" class="grid min-h-24 flex-1 content-start gap-2 overflow-y-auto p-2 pt-1" ghost-class="opacity-40" :animation="150" @change="(ch: Change) => onChange(col.key, ch)">
                    <template #item="{ element: i }">
                        <div class="cursor-grab rounded-xl border bg-card p-3 text-sm shadow-sm active:cursor-grabbing">
                            <div class="flex items-start justify-between gap-2"><p class="min-w-0 truncate font-semibold">{{ i.client?.name }}</p><span class="shrink-0 tabular-nums font-medium">{{ money(i.amount_total, i.currency) }}</span></div>
                            <p class="mt-0.5 truncate text-xs text-muted-foreground">{{ i.number ? i.number + ' · ' : '' }}{{ i.concept }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                                <span :class="['rounded-full px-2 py-0.5 font-medium', col.key === 'overdue' ? 'bg-red-500/10 text-red-600 dark:text-red-400' : 'bg-muted text-muted-foreground']">{{ col.key === 'paid' ? 'Pagada ' + fmtDate(i.paid_at) : 'Vence ' + fmtDate(i.due_date) }}</span>
                                <span v-if="col.key === 'scheduled' && !i.has_pdf" class="rounded-full bg-amber-500/10 px-2 py-0.5 font-medium text-amber-600 dark:text-amber-400">Falta el PDF</span>
                                <span v-if="i.sent_at && col.key !== 'paid'" class="rounded-full bg-sky-500/10 px-2 py-0.5 text-sky-600 dark:text-sky-400">Enviada</span>
                            </div>
                        </div>
                    </template>
                </draggable>
            </section>
        </div>
    </div>

    <InvoicePayDialog :invoice="payFor" @close="() => { payFor = null; reload(); }" @done="() => { payFor = null; reload(); }" />
    <InvoicePdfDialog :invoice="pdfFor" @close="() => { pdfFor = null; reload(); }" @done="() => { pdfFor = null; reload(); }" />
    <InvoiceFormDialog v-if="lookups" v-model:open="newOpen" :invoice="null" :clients="lookups.clients" :services="lookups.services" :tax-rate="lookups.taxRate" />
</template>
