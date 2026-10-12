<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlarmClock, CalendarClock, CircleAlert, FilePlus2, Repeat, TrendingUp, Users, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { cycleLabels, fmtDate, money } from '@/lib/billingUi';

type Client = { id: number; name: string } | null;
const props = defineProps<{
    kpis: { mrr: number; arr: number; accounts_active: number; services_active: number; avg_per_account: number; renewals_30: number; accounts_total: number };
    top_accounts: { client: Client; mrr: number; services: number; share: number }[];
    renewals: { id: number; name: string; client: Client; end_date: string; auto_renew: boolean; days: number; price: number; currency: string }[];
    without_services: { id: number; name: string }[];
    cycle_mix: { cycle: string; count: number; mrr: number }[];
    billing: null | {
        receivable: number; overdue: number; overdue_count: number; collected_month: number; to_issue: number;
        aging: { bucket: string; amount: number }[];
        series: { month: string; billed: number; collected: number }[];
        overdue_list: { id: number; number: string | null; concept: string; client: Client; due_date: string; days: number; currency: string; amount: number; total_clp: number | null }[];
        to_issue_list: { id: number; concept: string; client: Client; due_date: string; days: number }[];
    };
    costs: null | { billed_12m: number; expenses_12m: number; margin_12m: number; margin_pct: number | null };
    can: { billing: boolean; costs: boolean };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard de cuentas', href: '/accounts' }] } });

const cards = computed(() => [
    { label: 'Ingreso recurrente mensual', hint: 'Neto, equivalente mensual', value: money(props.kpis.mrr), icon: Repeat, color: '#6419DB' },
    { label: 'Ingreso anual proyectado', hint: 'MRR × 12', value: money(props.kpis.arr), icon: TrendingUp, color: '#4A8CFF' },
    { label: 'Cuentas activas', hint: `${props.kpis.services_active} servicios · ${money(props.kpis.avg_per_account)} por cuenta`, value: String(props.kpis.accounts_active), icon: Users, color: '#A855F7' },
    ...(props.billing ? [
        { label: 'Por cobrar', hint: `${props.billing.to_issue} cobro(s) por facturar`, value: money(props.billing.receivable), icon: Wallet, color: '#38BDF8' },
        { label: 'Vencido', hint: `${props.billing.overdue_count} factura(s)`, value: money(props.billing.overdue), icon: CircleAlert, color: '#EF4444' },
        { label: 'Cobrado este mes', hint: 'Con IVA', value: money(props.billing.collected_month), icon: AlarmClock, color: '#FFA165' },
    ] : []),
]);

const monthName = (d: string) => new Intl.DateTimeFormat('es-CL', { month: 'short' }).format(new Date(`${d}T12:00:00`));
const chartMax = computed(() => Math.max(1, ...(props.billing?.series ?? []).flatMap((s) => [s.billed, s.collected])));
const agingMax = computed(() => Math.max(1, ...(props.billing?.aging ?? []).map((a) => a.amount)));
const agingColor = ['#FFA165', '#F97316', '#EF4444'];
const daysLabel = (n: number) => (n === 0 ? 'hoy' : n === 1 ? 'mañana' : n < 0 ? `hace ${Math.abs(n)} d` : `en ${n} d`);
</script>

<template>
    <Head title="Dashboard de cuentas" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Dashboard de cuentas" description="Cómo están los clientes que ya tienen servicios: ingreso recurrente, cobranza y renovaciones.">
            <template #actions>
                <Button variant="outline" as-child><Link href="/accounts/kanban">Ver Kanban de cuentas</Link></Button>
                <Button variant="outline" as-child><Link href="/contracts">Servicios</Link></Button>
            </template>
        </PageHeader>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Indicadores">
            <DataCard v-for="c in cards" :key="c.label" class="flex items-center gap-4 p-4">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl" :style="{ backgroundColor: c.color + '1f', color: c.color }"><component :is="c.icon" class="size-5" /></div>
                <div class="min-w-0"><p class="truncate text-xs text-muted-foreground">{{ c.label }}</p><p class="text-2xl font-semibold tabular-nums">{{ c.value }}</p><p class="truncate text-xs text-muted-foreground">{{ c.hint }}</p></div>
            </DataCard>
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            <DataCard v-if="billing" class="p-5 xl:col-span-2">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">Facturado vs cobrado · últimos 6 meses</h2>
                    <div class="flex items-center gap-4 text-xs text-muted-foreground"><span class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-[#4A8CFF]" /> Facturado (neto)</span><span class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm bg-[#6419DB]" /> Cobrado (neto)</span></div>
                </div>
                <div class="flex h-44 items-end gap-2 sm:gap-4" role="img" aria-label="Facturado y cobrado por mes">
                    <div v-for="s in billing.series" :key="s.month" class="flex h-full flex-1 flex-col justify-end gap-1">
                        <div class="flex flex-1 items-end justify-center gap-1">
                            <div class="w-1/2 max-w-8 rounded-t-md bg-[#4A8CFF]" :style="{ height: Math.max(2, (s.billed / chartMax) * 100) + '%' }" :title="`Facturado ${money(s.billed)}`" />
                            <div class="w-1/2 max-w-8 rounded-t-md bg-[#6419DB]" :style="{ height: Math.max(2, (s.collected / chartMax) * 100) + '%' }" :title="`Cobrado ${money(s.collected)}`" />
                        </div>
                        <p class="text-center text-xs text-muted-foreground capitalize">{{ monthName(s.month) }}</p>
                    </div>
                </div>
            </DataCard>

            <DataCard v-if="billing" class="p-5">
                <h2 class="mb-1 font-semibold">Antigüedad de la deuda</h2>
                <p class="mb-4 text-xs text-muted-foreground">Facturas vencidas por días de atraso.</p>
                <div class="grid gap-3">
                    <div v-for="(a, i) in billing.aging" :key="a.bucket">
                        <div class="mb-1 flex justify-between text-sm"><span>{{ a.bucket }} días</span><span class="tabular-nums">{{ money(a.amount) }}</span></div>
                        <div class="h-2 rounded-full bg-muted"><div class="h-2 rounded-full" :style="{ width: (a.amount / agingMax) * 100 + '%', backgroundColor: agingColor[i] }" /></div>
                    </div>
                </div>
                <p v-if="!billing.overdue" class="mt-4 text-sm text-brand-green">Sin deuda vencida.</p>
            </DataCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <DataCard>
                <div class="flex items-center justify-between border-b px-5 py-3"><h2 class="font-semibold">Cuentas por ingreso recurrente</h2><span class="text-xs text-muted-foreground">{{ kpis.accounts_total }} con servicios</span></div>
                <p v-if="!top_accounts.length" class="px-5 py-6 text-sm text-muted-foreground">Aún no hay servicios recurrentes activos.</p>
                <ul class="divide-y">
                    <li v-for="a in top_accounts" :key="a.client?.id" class="px-5 py-3">
                        <div class="flex items-center justify-between gap-2 text-sm"><Link v-if="a.client" :href="`/clients/${a.client.id}`" class="truncate font-medium hover:text-primary">{{ a.client.name }}</Link><span class="shrink-0 tabular-nums">{{ money(a.mrr) }} <span class="text-xs text-muted-foreground">/ mes</span></span></div>
                        <div class="mt-1.5 flex items-center gap-2"><div class="h-1.5 flex-1 rounded-full bg-muted"><div class="h-1.5 rounded-full bg-primary" :style="{ width: Math.min(100, a.share) + '%' }" /></div><span class="w-20 text-right text-xs text-muted-foreground">{{ a.share }}% · {{ a.services }} serv.</span></div>
                    </li>
                </ul>
            </DataCard>

            <DataCard>
                <div class="flex items-center justify-between border-b px-5 py-3"><h2 class="flex items-center gap-2 font-semibold"><CalendarClock class="size-4" /> Renovaciones y términos · 90 días</h2></div>
                <p v-if="!renewals.length" class="px-5 py-6 text-sm text-muted-foreground">Nada vence en los próximos 90 días.</p>
                <ul class="divide-y">
                    <li v-for="r in renewals" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                        <div class="min-w-0"><Link :href="`/contracts/${r.id}`" class="block truncate font-medium hover:text-primary">{{ r.name }}</Link><p class="truncate text-xs text-muted-foreground">{{ r.client?.name }} · {{ fmtDate(r.end_date) }} ({{ daysLabel(r.days) }})</p></div>
                        <span :class="['rounded-full px-2.5 py-0.5 text-xs font-medium', r.auto_renew ? 'bg-brand-green/10 text-brand-green' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400']">{{ r.auto_renew ? 'Se renueva sola' : 'Hay que renovar' }}</span>
                    </li>
                </ul>
            </DataCard>
        </div>

        <div v-if="billing" class="grid gap-6 lg:grid-cols-2">
            <DataCard>
                <div class="flex items-center justify-between border-b px-5 py-3"><h2 class="flex items-center gap-2 font-semibold"><CircleAlert class="size-4 text-red-500" /> Facturas vencidas</h2><Link href="/billing?status=overdue" class="text-xs text-primary hover:underline">Ver todas</Link></div>
                <p v-if="!billing.overdue_list.length" class="px-5 py-6 text-sm text-brand-green">No hay facturas vencidas. ¡Todo al día!</p>
                <ul class="divide-y">
                    <li v-for="i in billing.overdue_list" :key="i.id" class="flex items-center justify-between gap-2 px-5 py-3 text-sm">
                        <div class="min-w-0"><p class="truncate font-medium">{{ i.client?.name }}</p><p class="truncate text-xs text-muted-foreground">{{ i.number ?? '' }} {{ i.concept }} · vencida hace {{ i.days }} d</p></div>
                        <span class="shrink-0 font-medium text-red-600 tabular-nums dark:text-red-400">{{ money(i.amount, i.currency) }}</span>
                    </li>
                </ul>
            </DataCard>

            <DataCard>
                <div class="flex items-center justify-between border-b px-5 py-3"><h2 class="flex items-center gap-2 font-semibold"><FilePlus2 class="size-4 text-amber-500" /> Cobros por facturar</h2><Link href="/billing?status=scheduled" class="text-xs text-primary hover:underline">Ver todos</Link></div>
                <p v-if="!billing.to_issue_list.length" class="px-5 py-6 text-sm text-muted-foreground">No hay cobros esperando factura.</p>
                <ul class="divide-y">
                    <li v-for="i in billing.to_issue_list" :key="i.id" class="flex items-center justify-between gap-2 px-5 py-3 text-sm">
                        <div class="min-w-0"><p class="truncate font-medium">{{ i.client?.name }}</p><p class="truncate text-xs text-muted-foreground">{{ i.concept }}</p></div>
                        <span :class="['shrink-0 text-xs', i.days < 0 ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted-foreground']">vence {{ daysLabel(i.days) }}</span>
                    </li>
                </ul>
            </DataCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <DataCard class="p-5">
                <h2 class="mb-3 font-semibold">Mezcla por ciclo de cobro</h2>
                <p v-if="!cycle_mix.length" class="text-sm text-muted-foreground">Sin servicios activos.</p>
                <ul class="grid gap-2 text-sm"><li v-for="m in cycle_mix" :key="m.cycle" class="flex items-center justify-between"><span>{{ cycleLabels[m.cycle] }} <span class="text-xs text-muted-foreground">· {{ m.count }} servicio(s)</span></span><span class="tabular-nums">{{ m.mrr ? money(m.mrr) + ' / mes' : '—' }}</span></li></ul>
                <div v-if="without_services.length" class="mt-4 border-t pt-4"><p class="mb-2 text-xs font-medium text-muted-foreground">Clientes con cobros pero sin servicios activos</p><div class="flex flex-wrap gap-2"><Link v-for="c in without_services" :key="c.id" :href="`/clients/${c.id}`" class="rounded-full bg-muted px-3 py-1 text-xs hover:bg-accent">{{ c.name }}</Link></div></div>
            </DataCard>

            <DataCard v-if="costs" class="p-5">
                <h2 class="mb-1 font-semibold">Rentabilidad · últimos 12 meses <span class="ml-1 rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">Solo interno</span></h2>
                <dl class="mt-3 grid grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-xs text-muted-foreground">Facturado (neto)</dt><dd class="text-lg font-semibold tabular-nums">{{ money(costs.billed_12m) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Gastos y costos</dt><dd class="text-lg font-semibold tabular-nums">{{ money(costs.expenses_12m) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Margen</dt><dd :class="['text-lg font-semibold tabular-nums', costs.margin_12m < 0 ? 'text-destructive' : '']">{{ money(costs.margin_12m) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Margen %</dt><dd class="text-lg font-semibold tabular-nums">{{ costs.margin_pct === null ? '—' : costs.margin_pct + '%' }}</dd></div>
                </dl>
            </DataCard>
        </div>
    </div>
</template>
