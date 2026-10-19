<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Ban, Check, Link2, Mail, Receipt, Search } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { formatMoney } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { index, link, pay, resend, voidMethod as voidInvoice } from '@/routes/billing/invoices';
import type { Paginated } from '@/types';

type Row = {
    id: number; number: string; concept: string; status: string; status_label: string; amount_net: number; amount_total: number; issued_at: string; due_date: string; days_overdue: number;
    paid_at: string | null; payment_method: string | null; payment_link: string | null; subscription_id: string | null; client: { id: number; name: string; customer_id: string } | null;
};
const props = defineProps<{
    invoices: Paginated<Row>; filters: { q: string; status: string }; methods: Record<string, string>;
    summary: Record<'pending' | 'overdue' | 'paid_month', { count: number; amount: number }>; can: { manage: boolean };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Cobros', href: index() }] } });

const filters = ref({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
useDebouncedFilters(index().url, filters, ['invoices', 'filters']);
const toggle = (k: string) => (filters.value.status = filters.value.status === k ? '' : k);

const colors: Record<string, string> = { pending: '#1AA0E4', overdue: '#DC2626', paid: '#0D9F85', void: '#8A98A8' };
const fmt = (d: string) => new Intl.DateTimeFormat('es-CL', { dateStyle: 'medium' }).format(new Date(d + 'T12:00:00'));

const paying = ref<Row | null>(null);
const payForm = useForm({ method: 'transferencia', reference: '', paid_at: new Date().toISOString().slice(0, 10), document_number: '' });
const openPay = (r: Row) => { paying.value = r; payForm.reset(); };
const submitPay = () => paying.value && payForm.post(pay(paying.value.id).url, { preserveScroll: true, onSuccess: () => (paying.value = null) });

const linking = ref<Row | null>(null);
const linkForm = useForm({ payment_link: '' });
const openLink = (r: Row) => { linking.value = r; linkForm.defaults({ payment_link: r.payment_link ?? '' }).reset(); };
const submitLink = () => linking.value && linkForm.put(link(linking.value.id).url, { preserveScroll: true, onSuccess: () => (linking.value = null) });

const toVoid = ref<Row | null>(null);
const confirmVoid = () => toVoid.value && router.post(voidInvoice(toVoid.value.id).url, {}, { preserveScroll: true, onFinish: () => (toVoid.value = null) });
const send = (r: Row) => router.post(resend(r.id).url, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Cobros" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Cobros" description="Cobros de suscripciones. Se emiten solos antes de cada renovación y los recordatorios se envían según Email → Automatizaciones." />

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <button type="button" :class="cn('rounded-2xl border bg-card p-4 text-left transition hover:border-primary/40', filters.status === 'pending' && 'border-primary ring-2 ring-primary/30')" @click="toggle('pending')"><span class="text-xs text-muted-foreground">Pendientes</span><span class="mt-1 block text-2xl font-bold">{{ formatMoney(summary.pending.amount) || '$0' }}</span><span class="text-[11px] text-muted-foreground">{{ summary.pending.count }} cobros</span></button>
            <button type="button" :class="cn('rounded-2xl border bg-card p-4 text-left transition hover:border-primary/40', filters.status === 'overdue' && 'border-primary ring-2 ring-primary/30')" @click="toggle('overdue')"><span class="text-xs text-muted-foreground">Vencidos</span><span class="mt-1 block text-2xl font-bold" :class="summary.overdue.amount ? 'text-destructive' : ''">{{ formatMoney(summary.overdue.amount) || '$0' }}</span><span class="text-[11px] text-muted-foreground">{{ summary.overdue.count }} cobros</span></button>
            <button type="button" :class="cn('rounded-2xl border bg-card p-4 text-left transition hover:border-primary/40', filters.status === 'paid' && 'border-primary ring-2 ring-primary/30')" @click="toggle('paid')"><span class="text-xs text-muted-foreground">Cobrado este mes</span><span class="mt-1 block text-2xl font-bold">{{ formatMoney(summary.paid_month.amount) || '$0' }}</span><span class="text-[11px] text-muted-foreground">{{ summary.paid_month.count }} pagos</span></button>
        </div>

        <DataCard>
            <div class="border-b p-4"><div class="relative max-w-md"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="filters.q" placeholder="Buscar por número, concepto, empresa o ID de cliente…" class="pl-9" /></div></div>
            <Table>
                <TableHeader><TableRow class="hover:bg-transparent"><TableHead>Cobro</TableHead><TableHead>Empresa</TableHead><TableHead>Estado</TableHead><TableHead class="text-right">Total (IVA incl.)</TableHead><TableHead>Vencimiento</TableHead><TableHead class="w-40" /></TableRow></TableHeader>
                <TableBody>
                    <TableEmpty v-if="!invoices.data.length" :colspan="6"><Receipt class="mx-auto mb-2 size-6 text-muted-foreground" />No hay cobros{{ filters.q || filters.status ? ' con esos filtros' : ' todavía' }}.</TableEmpty>
                    <TableRow v-for="r in invoices.data" :key="r.id">
                        <TableCell class="max-w-72"><p class="font-medium">{{ r.number }}</p><p class="truncate text-xs text-muted-foreground" :title="r.concept">{{ r.concept }}</p></TableCell>
                        <TableCell>{{ r.client?.name }}<p class="font-mono text-[11px] text-muted-foreground">{{ r.client?.customer_id }}</p></TableCell>
                        <TableCell><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: colors[r.status] }">{{ r.status_label }}</span><p v-if="r.status === 'paid' && r.paid_at" class="mt-0.5 text-[11px] text-muted-foreground">{{ fmt(r.paid_at.slice(0, 10)) }}</p></TableCell>
                        <TableCell class="text-right font-semibold">{{ formatMoney(r.amount_total) }}<p class="text-[11px] font-normal text-muted-foreground">neto {{ formatMoney(r.amount_net) }}</p></TableCell>
                        <TableCell>{{ fmt(r.due_date) }}<p v-if="r.days_overdue" class="text-xs text-destructive">{{ r.days_overdue }} días de atraso</p></TableCell>
                        <TableCell>
                            <div class="flex justify-end gap-0.5">
                                <template v-if="can.manage && (r.status === 'pending' || r.status === 'overdue')">
                                    <Button variant="ghost" size="icon-sm" title="Registrar pago" @click="openPay(r)"><Check /></Button>
                                    <Button variant="ghost" size="icon-sm" title="Reenviar aviso por correo" @click="send(r)"><Mail /></Button>
                                    <Button variant="ghost" size="icon-sm" title="Enlace de pago" @click="openLink(r)"><Link2 /></Button>
                                    <Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Anular" @click="toVoid = r"><Ban /></Button>
                                </template>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="invoices" />
        </DataCard>
    </div>

    <Dialog :open="!!paying" @update:open="(v: boolean) => !v && (paying = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Registrar pago {{ paying?.number }}</DialogTitle><DialogDescription>{{ paying?.client?.name }} · {{ formatMoney(paying?.amount_total) }}. Se envía el correo de «pago recibido» si está activo en Automatizaciones.</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitPay">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Medio de pago" for="pm"><NativeSelect id="pm" v-model="payForm.method"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></NativeSelect></FormField>
                    <FormField label="Fecha del pago" for="pf" :error="payForm.errors.paid_at"><Input id="pf" v-model="payForm.paid_at" type="date" /></FormField>
                </div>
                <FormField label="Referencia / N° de transferencia" for="pr"><Input id="pr" v-model="payForm.reference" /></FormField>
                <FormField label="N° de factura emitida (opcional)" for="pd"><Input id="pd" v-model="payForm.document_number" /></FormField>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="paying = null">Cancelar</Button><Button type="submit" :disabled="payForm.processing"><Spinner v-if="payForm.processing" /> Registrar pago</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="!!linking" @update:open="(v: boolean) => !v && (linking = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Enlace de pago {{ linking?.number }}</DialogTitle><DialogDescription>URL de pago online (Flow, Mercado Pago, etc.). Aparece en los correos y en la API como payment_link.</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitLink">
                <FormField label="URL" for="pl" :error="linkForm.errors.payment_link"><Input id="pl" v-model="linkForm.payment_link" type="url" placeholder="https://…" /></FormField>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="linking = null">Cancelar</Button><Button type="submit" :disabled="linkForm.processing"><Spinner v-if="linkForm.processing" /> Guardar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toVoid" title="Anular cobro" :description="`Se anulará el cobro ${toVoid?.number}. No se puede deshacer.`" confirm-label="Anular" @update:open="(v: boolean) => !v && (toVoid = null)" @confirm="confirmVoid" />
</template>
