<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Ban, Check, Copy, Infinity as InfinityIcon, PauseCircle, PlayCircle, Save } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from '@/lib/leadUi';
import { index as invoicesIndex, pay } from '@/routes/billing/invoices';
import { cancel, index, reactivate, suspend, update } from '@/routes/billing/subscriptions';
import { show as clientShow } from '@/routes/clients';

type Limit = { key: string; name: string; unit: string | null; value: number | null; unlimited: boolean };
type Inv = { id: number; number: string; concept: string; status: string; status_label: string; amount_total: number; due_date: string; days_overdue: number; paid_at: string | null };

const props = defineProps<{
    subscription: {
        id: string; db_id: number; status: string; status_label: string; access: boolean; plan: { id: string; name: string }; period: string; amount_net: number; amount_total: number; tax_rate: number;
        auto_renew: boolean; started_at: string; current_period_start: string; current_period_end: string; cancelled_at: string | null; cancel_reason: string | null; notes: string | null;
        limits: Limit[]; limit_overrides: Record<string, number | null>; contract_text: string | null;
        client: { id: number; name: string; legal_name: string | null; tax_id: string | null; email: string | null; customer_id: string };
        proposal: { id: number; number: string; title: string } | null;
    };
    invoices: Inv[]; limitTypes: { id: number; key: string; name: string; unit: string | null }[]; planLimits: Record<string, number | null>;
    plans: { id: number; name: string; prices: Record<string, number> }[]; periods: Record<string, string>; statuses: Record<string, string>; methods: Record<string, string>; can: { manage: boolean };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Suscripciones', href: index() }] } });

const s = props.subscription;
const colors: Record<string, string> = { active: '#0D9F85', past_due: '#D97706', suspended: '#8A98A8', cancelled: '#DC2626', expired: '#6366F1', pending: '#1AA0E4', overdue: '#DC2626', paid: '#0D9F85', void: '#8A98A8' };
const fmt = (d: string) => new Intl.DateTimeFormat('es-CL', { dateStyle: 'medium' }).format(new Date(d.slice(0, 10) + 'T12:00:00'));
const copy = (t: string) => navigator.clipboard.writeText(t).then(() => toast.success('Copiado'));

const planId = ref<number>(props.plans.find((p) => p.name === s.plan.name)?.id ?? 0);
const form = useForm({
    auto_renew: s.auto_renew, notes: s.notes ?? '', plan_id: planId.value as number | string, period: s.period, amount: s.amount_net as number | string,
    limit_overrides: Object.fromEntries(props.limitTypes.map((l) => [l.key, s.limit_overrides[l.key] === undefined || s.limit_overrides[l.key] === null ? '' : String(s.limit_overrides[l.key])])) as Record<string, string>,
    limit_unlimited: Object.fromEntries(props.limitTypes.map((l) => [l.key, l.key in s.limit_overrides && s.limit_overrides[l.key] === null])) as Record<string, boolean>,
});
const listPrice = computed(() => props.plans.find((p) => p.id === Number(form.plan_id))?.prices[form.period]);
const save = () => form.put(update(s.db_id).url, { preserveScroll: true });

const confirmCancel = ref(false);
const doCancel = () => router.post(cancel(s.db_id).url, {}, { preserveScroll: true, onFinish: () => (confirmCancel.value = false) });
const act = (url: string) => router.post(url, {}, { preserveScroll: true });
const markPaid = (i: Inv) => router.post(pay(i.id).url, { method: 'transferencia' }, { preserveScroll: true });
const live = computed(() => ['active', 'past_due'].includes(s.status));
</script>

<template>
    <Head :title="`Suscripción · ${s.client.name}`" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <Link :href="index()" class="mb-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"><ArrowLeft class="size-3.5" /> Suscripciones</Link>
                <h1 class="text-2xl font-semibold tracking-tight">{{ s.client.name }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: colors[s.status] }">{{ s.status_label }}</span>
                    Plan {{ s.plan.name }} · {{ periods[s.period] }}
                    <span v-if="!s.access" class="font-medium text-destructive">· sin acceso en la app</span>
                </p>
            </div>
            <div v-if="can.manage" class="flex flex-wrap gap-2">
                <Button v-if="s.status === 'suspended' || s.status === 'cancelled' || s.status === 'expired'" variant="outline" @click="act(reactivate(s.db_id).url)"><PlayCircle /> Reactivar</Button>
                <Button v-if="live" variant="outline" @click="act(suspend(s.db_id).url)"><PauseCircle /> Suspender acceso</Button>
                <Button v-if="live || s.status === 'suspended'" variant="outline" class="text-destructive hover:text-destructive" @click="confirmCancel = true"><Ban /> Cancelar</Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-2xl border bg-card p-5 lg:col-span-1">
                <h2 class="mb-3 text-sm font-semibold">Cliente y vigencia</h2>
                <dl class="grid gap-2.5 text-sm">
                    <div><dt class="text-xs text-muted-foreground">ID de cliente (tenant)</dt><dd class="flex items-center gap-1 font-mono text-xs">{{ s.client.customer_id }} <button type="button" title="Copiar" class="text-muted-foreground hover:text-foreground" @click="copy(s.client.customer_id)"><Copy class="size-3.5" /></button></dd></div>
                    <div><dt class="text-xs text-muted-foreground">ID de suscripción</dt><dd class="font-mono text-xs">{{ s.id }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Empresa</dt><dd><Link :href="clientShow(s.client.id)" class="underline-offset-2 hover:underline">{{ s.client.legal_name || s.client.name }}</Link><template v-if="s.client.tax_id"> · {{ s.client.tax_id }}</template></dd></div>
                    <div><dt class="text-xs text-muted-foreground">Correo de cobranza</dt><dd>{{ s.client.email || '—' }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Valor del período</dt><dd class="font-semibold">{{ formatMoney(s.amount_net) }} neto · {{ formatMoney(s.amount_total) }} con IVA</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Inicio</dt><dd>{{ fmt(s.started_at) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Período vigente</dt><dd>{{ fmt(s.current_period_start) }} → {{ fmt(s.current_period_end) }}</dd></div>
                    <div v-if="s.cancelled_at"><dt class="text-xs text-muted-foreground">Cancelada</dt><dd>{{ fmt(s.cancelled_at) }}<template v-if="s.cancel_reason"> · {{ s.cancel_reason }}</template></dd></div>
                    <div v-if="s.proposal"><dt class="text-xs text-muted-foreground">Propuesta</dt><dd>{{ s.proposal.number }} · {{ s.proposal.title }}</dd></div>
                </dl>
            </section>

            <section class="rounded-2xl border bg-card p-5 lg:col-span-2">
                <h2 class="mb-3 text-sm font-semibold">Configuración</h2>
                <form class="grid gap-4" @submit.prevent="save">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField label="Plan" for="ep"><NativeSelect id="ep" v-model="form.plan_id" :disabled="!can.manage"><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></NativeSelect></FormField>
                        <FormField label="Período" for="ed"><NativeSelect id="ed" v-model="form.period" :disabled="!can.manage"><option v-for="(l, k) in periods" :key="k" :value="k">{{ l }}</option></NativeSelect></FormField>
                        <FormField label="Precio neto del período" for="ea" :hint="listPrice ? `Lista: ${formatMoney(listPrice)}` : undefined"><Input id="ea" v-model="form.amount" type="number" min="0" :disabled="!can.manage" /></FormField>
                    </div>
                    <p class="-mt-2 text-xs text-muted-foreground">Los cambios de plan, período o precio rigen desde el próximo cobro.</p>
                    <label class="flex items-start justify-between gap-4 text-sm"><span><span class="font-medium">Renovación automática</span><br /><span class="text-xs text-muted-foreground">Si está activa se emite el cobro del siguiente período antes de que termine el actual; si no, la suscripción finaliza al término.</span></span><Switch :model-value="form.auto_renew" :disabled="!can.manage" @update:model-value="(v: boolean) => (form.auto_renew = v)" /></label>

                    <div class="rounded-xl border p-4">
                        <p class="text-sm font-medium">Límites <span class="text-xs font-normal text-muted-foreground">(los del plan, con ajustes solo para este cliente)</span></p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div v-for="l in limitTypes" :key="l.key" class="grid gap-1.5">
                                <label class="text-xs text-muted-foreground">{{ l.name }}<template v-if="l.unit"> ({{ l.unit }})</template> · plan: {{ l.key in planLimits ? (planLimits[l.key] === null ? 'ilimitado' : planLimits[l.key]) : '—' }}</label>
                                <div class="flex items-center gap-2">
                                    <Input v-model="form.limit_overrides[l.key]" type="number" min="0" placeholder="Igual al plan" :disabled="!can.manage || form.limit_unlimited[l.key]" />
                                    <label class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground" title="Ilimitado solo para este cliente"><Switch :model-value="form.limit_unlimited[l.key]" :disabled="!can.manage" @update:model-value="(v: boolean) => (form.limit_unlimited[l.key] = v)" /><InfinityIcon class="size-4" /></label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <FormField label="Notas internas" for="en"><Textarea id="en" v-model="form.notes" rows="2" :disabled="!can.manage" /></FormField>
                    <div v-if="can.manage" class="flex justify-end"><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /><Save v-else /> Guardar cambios</Button></div>
                </form>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl border bg-card">
            <div class="flex items-center justify-between border-b p-4"><h2 class="text-sm font-semibold">Cobros de esta suscripción</h2><Link :href="invoicesIndex()" class="text-xs text-primary hover:underline">Ver todos los cobros</Link></div>
            <Table>
                <TableHeader><TableRow class="hover:bg-transparent"><TableHead>Cobro</TableHead><TableHead>Estado</TableHead><TableHead class="text-right">Total</TableHead><TableHead>Vencimiento</TableHead><TableHead class="w-12" /></TableRow></TableHeader>
                <TableBody>
                    <TableRow v-for="i in invoices" :key="i.id">
                        <TableCell class="max-w-80"><p class="font-medium">{{ i.number }}</p><p class="truncate text-xs text-muted-foreground" :title="i.concept">{{ i.concept }}</p></TableCell>
                        <TableCell><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: colors[i.status] }">{{ i.status_label }}</span></TableCell>
                        <TableCell class="text-right font-semibold">{{ formatMoney(i.amount_total) }}</TableCell>
                        <TableCell>{{ fmt(i.due_date) }}<p v-if="i.days_overdue" class="text-xs text-destructive">{{ i.days_overdue }} días de atraso</p></TableCell>
                        <TableCell><Button v-if="can.manage && ['pending', 'overdue'].includes(i.status)" variant="ghost" size="icon-sm" title="Marcar como pagado (transferencia)" @click="markPaid(i)"><Check /></Button></TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </section>

        <details v-if="s.contract_text" class="rounded-2xl border bg-card p-5 text-sm">
            <summary class="cursor-pointer font-semibold">Contrato de servicios aceptado</summary>
            <div class="prose prose-sm mt-3 max-w-none text-muted-foreground [&_h3]:mb-2 [&_h3]:text-foreground [&_p]:mb-2" v-html="s.contract_text" />
        </details>
    </div>

    <ConfirmDialog :open="confirmCancel" title="Cancelar suscripción" description="Se cancelan la renovación y los cobros futuros pendientes. El servicio queda sin acceso según la API." confirm-label="Cancelar suscripción" @update:open="(v: boolean) => (confirmCancel = v)" @confirm="doCancel" />
</template>
