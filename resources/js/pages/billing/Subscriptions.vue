<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CalendarClock, Copy, Plus, Repeat, Search, TrendingUp, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { formatMoney, formatMoneyShort } from '@/lib/leadUi';
import { index as plansIndex } from '@/routes/billing/plans';
import { index, show, store } from '@/routes/billing/subscriptions';
import type { Paginated } from '@/types';

type Row = { id: number; uid: string; status: string; period: string; amount: number; total: number; auto_renew: boolean; current_period_end: string; started_at: string; client: { id: number; name: string; customer_id: string } | null; plan: { id: number; name: string } | null };

const props = defineProps<{
    subscriptions: Paginated<Row>;
    filters: { q: string; status: string; plan: string };
    statuses: Record<string, string>;
    plans: { id: number; name: string }[];
    kpis: { active: number; mrr: number; past_due: number; pending_amount: number; overdue_amount: number; renewing: number };
    formData: { clients: { id: number; name: string; customer_id: string }[]; plans: { id: number; name: string; prices: Record<string, number> }[]; periods: Record<string, string> };
    can: { manage: boolean };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Suscripciones', href: index() }] } });

const filters = ref({ q: props.filters.q ?? '', status: props.filters.status ?? '', plan: props.filters.plan ?? '' });
useDebouncedFilters(index().url, filters, ['subscriptions', 'filters']);

const colors: Record<string, string> = { active: '#0D9F85', past_due: '#D97706', suspended: '#8A98A8', cancelled: '#DC2626', expired: '#6366F1' };
const periodShort: Record<string, string> = { monthly: 'mes', quarterly: 'trimestre', annual: 'año' };
const fmt = (d: string) => new Intl.DateTimeFormat('es-CL', { dateStyle: 'medium' }).format(new Date(d + 'T12:00:00'));
const daysTo = (d: string) => Math.round((new Date(d + 'T12:00:00').getTime() - Date.now()) / 86400000);
const copy = (t: string) => navigator.clipboard.writeText(t).then(() => toast.success('ID de cliente copiado'));

// ------------------------------------------------------------ nueva suscripción manual
const open = ref(false);
const form = useForm({ client_id: '' as number | string, plan_id: '' as number | string, period: 'monthly', amount: '' as number | string, auto_renew: true, started_at: new Date().toISOString().slice(0, 10), notes: '' });
const plan = computed(() => props.formData.plans.find((p) => p.id === Number(form.plan_id)));
const listPrice = computed(() => plan.value?.prices[form.period]);
const save = () => form.transform((d) => ({ ...d, amount: d.amount === '' ? null : d.amount })).post(store().url, { preserveScroll: true, onSuccess: () => (open.value = false) });
</script>

<template>
    <Head title="Suscripciones" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Suscripciones" description="Talleres con un plan contratado. Las suscripciones nacen al aceptarse una propuesta con plan, o se crean a mano. Cada empresa tiene un ID de cliente para vincular la app y el sitio.">
            <template #actions>
                <Button variant="outline" as-child><Link :href="plansIndex()"><Repeat /> Planes y límites</Link></Button>
                <Button v-if="can.manage" @click="open = true"><Plus /> Nueva suscripción</Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="rounded-2xl border bg-card p-4"><p class="flex items-center gap-1.5 text-xs text-muted-foreground"><Repeat class="size-3.5" /> Vigentes</p><p class="mt-1 text-2xl font-bold">{{ kpis.active }}</p></div>
            <div class="rounded-2xl border bg-card p-4"><p class="flex items-center gap-1.5 text-xs text-muted-foreground"><TrendingUp class="size-3.5" /> MRR neto</p><p class="mt-1 text-2xl font-bold">{{ formatMoneyShort(kpis.mrr) || '$0' }}</p><p class="text-[11px] text-muted-foreground">ingreso mensual recurrente</p></div>
            <div class="rounded-2xl border bg-card p-4"><p class="flex items-center gap-1.5 text-xs text-muted-foreground"><Wallet class="size-3.5" /> Por cobrar</p><p class="mt-1 text-2xl font-bold">{{ formatMoneyShort(kpis.pending_amount) || '$0' }}</p><p class="text-[11px] text-muted-foreground">IVA incluido</p></div>
            <div class="rounded-2xl border bg-card p-4"><p class="flex items-center gap-1.5 text-xs text-muted-foreground"><AlertTriangle class="size-3.5" /> Vencido</p><p class="mt-1 text-2xl font-bold" :class="kpis.overdue_amount ? 'text-destructive' : ''">{{ formatMoneyShort(kpis.overdue_amount) || '$0' }}</p><p class="text-[11px] text-muted-foreground">{{ kpis.past_due }} suscripciones con atraso</p></div>
            <div class="rounded-2xl border bg-card p-4"><p class="flex items-center gap-1.5 text-xs text-muted-foreground"><CalendarClock class="size-3.5" /> Renuevan en 30 días</p><p class="mt-1 text-2xl font-bold">{{ kpis.renewing }}</p></div>
        </div>

        <DataCard>
            <div class="flex flex-col gap-3 border-b p-4 sm:flex-row">
                <div class="relative max-w-md flex-1"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="filters.q" placeholder="Buscar por empresa, RUT o ID de cliente…" class="pl-9" /></div>
                <NativeSelect v-model="filters.status" class="sm:w-48"><option value="">Todos los estados</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></NativeSelect>
                <NativeSelect v-model="filters.plan" class="sm:w-44"><option value="">Todos los planes</option><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></NativeSelect>
            </div>
            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent"><TableHead>Empresa</TableHead><TableHead>Plan</TableHead><TableHead>Estado</TableHead><TableHead class="text-right">Valor</TableHead><TableHead>Próximo cobro / término</TableHead><TableHead>Renovación</TableHead></TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="!subscriptions.data.length" :colspan="6"><Repeat class="mx-auto mb-2 size-6 text-muted-foreground" />Aún no hay suscripciones{{ filters.q || filters.status || filters.plan ? ' con esos filtros' : '' }}.</TableEmpty>
                    <TableRow v-for="s in subscriptions.data" :key="s.id" class="cursor-pointer" @click="router.visit(show(s.id).url)">
                        <TableCell>
                            <p class="font-medium">{{ s.client?.name }}</p>
                            <button v-if="s.client" type="button" class="flex items-center gap-1 font-mono text-[11px] text-muted-foreground hover:text-foreground" title="Copiar ID de cliente" @click.stop="copy(s.client.customer_id)">{{ s.client.customer_id }} <Copy class="size-3" /></button>
                        </TableCell>
                        <TableCell>{{ s.plan?.name }}<p class="text-xs text-muted-foreground">{{ formData.periods[s.period] }}</p></TableCell>
                        <TableCell><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: colors[s.status] }">{{ statuses[s.status] }}</span></TableCell>
                        <TableCell class="text-right font-semibold">{{ formatMoney(s.amount) }}<p class="text-[11px] font-normal text-muted-foreground">neto / {{ periodShort[s.period] }}</p></TableCell>
                        <TableCell>{{ fmt(s.current_period_end) }}<p class="text-xs text-muted-foreground">{{ daysTo(s.current_period_end) >= 0 ? `en ${daysTo(s.current_period_end)} días` : `hace ${-daysTo(s.current_period_end)} días` }}</p></TableCell>
                        <TableCell class="text-muted-foreground">{{ s.auto_renew ? 'Automática' : 'No renueva' }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="subscriptions" />
        </DataCard>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader><DialogTitle>Nueva suscripción</DialogTitle><DialogDescription>Para clientes que contratan sin pasar por una propuesta. Se emite el primer cobro y se envían los correos configurados.</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <FormField label="Empresa" for="sc" :error="form.errors.client_id" required><NativeSelect id="sc" v-model="form.client_id"><option value="">Elige una empresa…</option><option v-for="c in formData.clients" :key="c.id" :value="c.id">{{ c.name }}</option></NativeSelect></FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Plan" for="sp" :error="form.errors.plan_id" required><NativeSelect id="sp" v-model="form.plan_id"><option value="">Elige un plan…</option><option v-for="p in formData.plans" :key="p.id" :value="p.id">{{ p.name }}</option></NativeSelect></FormField>
                    <FormField label="Período" for="sd" :error="form.errors.period"><NativeSelect id="sd" v-model="form.period"><option v-for="(l, k) in formData.periods" :key="k" :value="k">{{ l }}</option></NativeSelect></FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Precio neto del período" for="sa" :hint="listPrice ? `Lista: ${formatMoney(listPrice)}. Vacío = precio de lista.` : 'Vacío = precio de lista.'"><Input id="sa" v-model="form.amount" type="number" min="0" inputmode="numeric" :placeholder="listPrice ? String(listPrice) : ''" /></FormField>
                    <FormField label="Inicio" for="si" :error="form.errors.started_at"><Input id="si" v-model="form.started_at" type="date" /></FormField>
                </div>
                <label class="flex items-center gap-3 text-sm"><Switch :model-value="form.auto_renew" @update:model-value="(v: boolean) => (form.auto_renew = v)" /> Renovación automática</label>
                <p v-if="form.errors.period" class="text-sm text-destructive">{{ form.errors.period }}</p>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="open = false">Cancelar</Button><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Crear suscripción</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
