<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    FileSignature,
    Landmark,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { clp, pct, toClp, uf as fmtUf } from '@/lib/realEstate';

type Payment = {
    id: number;
    kind: string;
    number: number;
    label: string;
    currency: 'UF' | 'CLP';
    amount: number;
    status: string;
    paid_clp: number | null;
    method: string | null;
    reference: string | null;
    notes: string | null;
    due_date: string | null;
    paid_at: string | null;
    overdue: boolean;
};
type Reservation = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    status_color: string;
    project: { id: number; name: string; color: string } | null;
    units: string;
    total_uf: number;
    subtotal_uf: number;
    discount_uf: number;
    pie_pct: number;
    pie_uf: number;
    credit_uf: number;
    financing: string;
    bank: string | null;
    notes: string | null;
    cancel_reason: string | null;
    uf_value: number;
    owner: string | null;
    reserved_at: string | null;
    promise_due_date: string | null;
    promised_at: string | null;
    deeded_at: string | null;
    cancelled_at: string | null;
    client: {
        id: number;
        name: string;
        tax_id: string | null;
        email: string | null;
        phone: string | null;
        address: string | null;
        commune: string | null;
    } | null;
    executive: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
    } | null;
    quote: { id: number; number: string } | null;
    lead: { id: number; name: string } | null;
    unit_rows: {
        id: number;
        label: string;
        model: string | null;
        list_price_uf: number;
        price_uf: number;
        status: string | null;
        detail: string | null;
    }[];
    payments: Payment[];
    progress: {
        paid_uf: number;
        pie_paid_uf: number;
        overdue: number;
        next: {
            label: string;
            due_date: string | null;
            amount: number;
            currency: string;
        } | null;
    };
};

const props = defineProps<{
    reservation: Reservation;
    uf: { value: number; date: string } | null;
    kinds: Record<string, string>;
    methods: string[];
    paymentStatuses: Record<string, string>;
    financing: Record<string, string>;
    can: { manage: boolean; payments: boolean };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Reservas', href: '/reservations' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const r = computed(() => props.reservation);
const today = new Date().toISOString().slice(0, 10);
const date = (d: string | null) =>
    d ? new Date(`${d}T12:00:00`).toLocaleDateString('es-CL') : '—';
const money = (p: { currency: string; amount: number }) =>
    p.currency === 'UF' ? fmtUf(p.amount) : clp(p.amount);
const active = computed(
    () => !['cancelled', 'deeded'].includes(r.value.status),
);
const piePct = computed(() =>
    r.value.pie_uf > 0
        ? Math.min(
              100,
              Math.round((r.value.progress.pie_paid_uf / r.value.pie_uf) * 100),
          )
        : 0,
);
const promiseLate = computed(
    () =>
        r.value.status === 'reserved' &&
        !!r.value.promise_due_date &&
        r.value.promise_due_date < today,
);
const totals = computed(() => {
    const pending = r.value.payments.filter((p) => p.status === 'pending');
    return {
        pendingUf: pending.reduce(
            (s, p) =>
                s +
                (p.currency === 'UF'
                    ? p.amount
                    : r.value.uf_value > 0
                      ? p.amount / r.value.uf_value
                      : 0),
            0,
        ),
        count: pending.length,
    };
});

// ---------------------------------------------------------------- transiciones
type Step = 'promised' | 'deeded' | 'cancelled';
const stepOpen = ref(false);
const step = useForm({ status: 'promised' as Step, date: today, reason: '' });
const stepCopy: Record<Step, { title: string; text: string; cta: string }> = {
    promised: {
        title: 'Registrar promesa',
        text: 'Las unidades pasan a «promesada». Registra la fecha de firma de la promesa de compraventa.',
        cta: 'Registrar promesa',
    },
    deeded: {
        title: 'Registrar escritura',
        text: 'Las unidades quedan vendidas y el negocio se cierra.',
        cta: 'Registrar escritura',
    },
    cancelled: {
        title: 'Desistir reserva',
        text: 'Las unidades vuelven a estar disponibles, los pagos pendientes se anulan y la cotización queda perdida. Los pagos ya registrados se mantienen como historial.',
        cta: 'Desistir',
    },
};
const openStep = (s: Step) => {
    step.status = s;
    step.date = today;
    step.reason = '';
    step.clearErrors();
    stepOpen.value = true;
};
const doStep = () =>
    step.post(`/reservations/${r.value.id}/transition`, {
        preserveScroll: true,
        onSuccess: () => (stepOpen.value = false),
    });

// ---------------------------------------------------------------- datos de la reserva
const editOpen = ref(false);
const edit = useForm({
    promise_due_date: '',
    financing: 'credito',
    bank: '',
    notes: '',
});
const openEdit = () => {
    edit.promise_due_date = r.value.promise_due_date ?? '';
    edit.financing = r.value.financing;
    edit.bank = r.value.bank ?? '';
    edit.notes = r.value.notes ?? '';
    editOpen.value = true;
};
const doEdit = () =>
    edit.put(`/reservations/${r.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editOpen.value = false),
    });

// ---------------------------------------------------------------- pagos
const payOpen = ref(false);
const paying = ref<Payment | null>(null);
const pay = useForm({
    paid_at: today,
    paid_clp: 0 as number | undefined,
    method: 'Transferencia',
    reference: '',
    notes: '',
});
const openPay = (p: Payment) => {
    paying.value = p;
    pay.paid_at = today;
    pay.paid_clp =
        p.currency === 'CLP'
            ? p.amount
            : props.uf
              ? toClp(p.amount, props.uf.value)
              : undefined;
    pay.method =
        p.kind === 'saldo' && r.value.financing === 'credito'
            ? 'Crédito hipotecario'
            : 'Transferencia';
    pay.reference = '';
    pay.notes = p.notes ?? '';
    pay.clearErrors();
    payOpen.value = true;
};
const doPay = () =>
    paying.value &&
    pay.post(`/reservations/${r.value.id}/payments/${paying.value.id}/pay`, {
        preserveScroll: true,
        onSuccess: () => (payOpen.value = false),
    });
const unpay = (p: Payment) =>
    router.post(
        `/reservations/${r.value.id}/payments/${p.id}/unpay`,
        {},
        { preserveScroll: true },
    );

const rowOpen = ref(false);
const editingRow = ref<Payment | null>(null);
const row = useForm({
    kind: 'pie',
    number: 0,
    label: '',
    due_date: '',
    currency: 'UF' as 'UF' | 'CLP',
    amount: 0,
    notes: '',
});
const openRow = (p: Payment | null) => {
    editingRow.value = p;
    row.kind = p?.kind ?? 'otro';
    row.number = p?.number ?? 0;
    row.label = p?.label ?? '';
    row.due_date = p?.due_date ?? today;
    row.currency = p?.currency ?? 'UF';
    row.amount = p?.amount ?? 0;
    row.notes = p?.notes ?? '';
    row.clearErrors();
    rowOpen.value = true;
};
const doRow = () => {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (rowOpen.value = false),
    };
    if (editingRow.value)
        row.put(
            `/reservations/${r.value.id}/payments/${editingRow.value.id}`,
            opts,
        );
    else row.post(`/reservations/${r.value.id}/payments`, opts);
};
const toDelete = ref<Payment | null>(null);
const destroyRow = () =>
    toDelete.value &&
    router.delete(`/reservations/${r.value.id}/payments/${toDelete.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (toDelete.value = null),
    });

const statusClass = (p: Payment) =>
    p.status === 'paid'
        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
        : p.status === 'cancelled'
          ? 'bg-muted text-muted-foreground line-through'
          : p.overdue
            ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'
            : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300';
</script>

<template>
    <Head :title="`Reserva ${reservation.number}`" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Reserva {{ reservation.number }}
                    </h1>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                        :style="{ backgroundColor: reservation.status_color }"
                        >{{ reservation.status_label }}</span
                    >
                </div>
                <p
                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                >
                    <span
                        v-if="reservation.project"
                        class="flex items-center gap-1.5"
                        ><span
                            class="size-2 rounded-full"
                            :style="{
                                backgroundColor: reservation.project.color,
                            }"
                        /><Link
                            :href="`/projects/${reservation.project.id}`"
                            class="hover:underline"
                            >{{ reservation.project.name }}</Link
                        ></span
                    >
                    <span>{{ reservation.units }}</span>
                    <span v-if="reservation.quote"
                        >Cotización
                        <Link
                            :href="`/quotes/${reservation.quote.id}`"
                            class="text-primary hover:underline"
                            >{{ reservation.quote.number }}</Link
                        ></span
                    >
                </p>
            </div>
            <div v-if="can.manage && active" class="flex flex-wrap gap-2">
                <Button
                    v-if="reservation.status === 'reserved'"
                    @click="openStep('promised')"
                    ><FileSignature /> Registrar promesa</Button
                >
                <Button
                    v-if="reservation.status === 'promised'"
                    @click="openStep('deeded')"
                    ><Landmark /> Registrar escritura</Button
                >
                <Button variant="outline" @click="openEdit"
                    ><Pencil /> Editar</Button
                >
                <Button
                    variant="ghost"
                    class="text-destructive hover:text-destructive"
                    @click="openStep('cancelled')"
                    ><Ban /> Desistir</Button
                >
            </div>
        </div>

        <div
            v-if="reservation.status === 'cancelled'"
            class="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm"
        >
            Reserva desistida el {{ date(reservation.cancelled_at)
            }}{{
                reservation.cancel_reason
                    ? `: ${reservation.cancel_reason}`
                    : ''
            }}.
        </div>
        <div
            v-else-if="promiseLate"
            class="flex items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
        >
            <AlertTriangle class="size-4 shrink-0" /> El plazo para firmar la
            promesa venció el {{ date(reservation.promise_due_date) }}.
        </div>

        <!-- Hitos -->
        <ol class="grid grid-cols-3 gap-2 text-center text-xs">
            <li
                v-for="(m, i) in [
                    { k: 'reserved', l: 'Reserva', d: reservation.reserved_at },
                    {
                        k: 'promised',
                        l: 'Promesa',
                        d:
                            reservation.promised_at ??
                            (reservation.status === 'reserved'
                                ? reservation.promise_due_date
                                : null),
                    },
                    { k: 'deeded', l: 'Escritura', d: reservation.deeded_at },
                ]"
                :key="m.k"
                class="rounded-xl border p-3"
                :class="
                    ['reserved', 'promised', 'deeded'].indexOf(
                        reservation.status,
                    ) >= i && reservation.status !== 'cancelled'
                        ? 'border-primary bg-primary/5'
                        : 'bg-card'
                "
            >
                <span class="block font-semibold">{{ m.l }}</span>
                <span class="text-muted-foreground">{{
                    m.k === 'promised' && !reservation.promised_at && m.d
                        ? `Plazo ${date(m.d)}`
                        : date(m.d)
                }}</span>
            </li>
        </ol>

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <div class="flex flex-col gap-6">
                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Total de la compra
                        </p>
                        <p class="text-xl font-semibold tabular-nums">
                            {{ fmtUf(reservation.total_uf) }}
                        </p>
                        <p
                            v-if="uf"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            ≈ {{ clp(toClp(reservation.total_uf, uf.value)) }}
                        </p>
                    </div>
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Pie pagado ({{ pct(reservation.pie_pct) }} =
                            {{ fmtUf(reservation.pie_uf) }})
                        </p>
                        <p class="text-xl font-semibold tabular-nums">
                            {{ fmtUf(reservation.progress.pie_paid_uf) }}
                        </p>
                        <div
                            class="mt-2 h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-primary"
                                :style="{ width: `${piePct}%` }"
                            />
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ piePct }}% del pie
                        </p>
                    </div>
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Próximo pago
                        </p>
                        <template v-if="reservation.progress.next">
                            <p class="text-xl font-semibold tabular-nums">
                                {{ money(reservation.progress.next) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ reservation.progress.next.label }} ·
                                {{ date(reservation.progress.next.due_date) }}
                            </p>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            Sin pagos pendientes
                        </p>
                        <p
                            v-if="reservation.progress.overdue"
                            class="mt-1 flex items-center gap-1 text-xs font-medium text-destructive"
                        >
                            <AlertTriangle class="size-3" />{{
                                reservation.progress.overdue
                            }}
                            vencido{{
                                reservation.progress.overdue > 1 ? 's' : ''
                            }}
                        </p>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border bg-card">
                    <header
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <div>
                            <h2 class="font-semibold">Plan de pagos</h2>
                            <p class="text-xs text-muted-foreground">
                                {{ totals.count }} pendientes ·
                                {{ fmtUf(totals.pendingUf) }} por pagar · UF de
                                la reserva {{ clp(reservation.uf_value) }}
                            </p>
                        </div>
                        <Button
                            v-if="
                                can.payments &&
                                reservation.status !== 'cancelled'
                            "
                            size="sm"
                            variant="outline"
                            @click="openRow(null)"
                            ><Plus /> Agregar pago</Button
                        >
                    </header>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead
                                class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-3 py-2">Concepto</th>
                                    <th class="px-3 py-2">Vence</th>
                                    <th class="px-3 py-2 text-right">Monto</th>
                                    <th class="px-3 py-2">Estado</th>
                                    <th class="px-3 py-2 text-right" />
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="p in reservation.payments"
                                    :key="p.id"
                                    :class="
                                        p.status === 'cancelled'
                                            ? 'opacity-60'
                                            : ''
                                    "
                                >
                                    <td class="px-3 py-2">
                                        <span class="font-medium">{{
                                            p.label
                                        }}</span>
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{ kinds[p.kind]
                                            }}<template v-if="p.notes">
                                                · {{ p.notes }}</template
                                            ></span
                                        >
                                    </td>
                                    <td
                                        class="px-3 py-2"
                                        :class="
                                            p.overdue
                                                ? 'font-medium text-destructive'
                                                : ''
                                        "
                                    >
                                        {{ date(p.due_date) }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <span
                                            class="font-semibold tabular-nums"
                                            >{{ money(p) }}</span
                                        >
                                        <span
                                            v-if="
                                                p.currency === 'UF' &&
                                                uf &&
                                                p.status === 'pending'
                                            "
                                            class="block text-xs text-muted-foreground tabular-nums"
                                            >≈
                                            {{
                                                clp(toClp(p.amount, uf.value))
                                            }}</span
                                        >
                                        <span
                                            v-else-if="
                                                p.status === 'paid' &&
                                                p.paid_clp &&
                                                p.currency === 'UF'
                                            "
                                            class="block text-xs text-muted-foreground tabular-nums"
                                            >{{ clp(p.paid_clp) }} pagados</span
                                        >
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                            :class="statusClass(p)"
                                            >{{
                                                p.overdue
                                                    ? 'Vencido'
                                                    : paymentStatuses[p.status]
                                            }}</span
                                        >
                                        <span
                                            v-if="p.status === 'paid'"
                                            class="block text-xs text-muted-foreground"
                                            >{{ date(p.paid_at)
                                            }}{{
                                                p.method
                                                    ? ` · ${p.method}`
                                                    : ''
                                            }}{{
                                                p.reference
                                                    ? ` · ${p.reference}`
                                                    : ''
                                            }}</span
                                        >
                                    </td>
                                    <td class="px-3 py-2">
                                        <div
                                            v-if="
                                                can.payments &&
                                                reservation.status !==
                                                    'cancelled'
                                            "
                                            class="flex justify-end gap-0.5"
                                        >
                                            <Button
                                                v-if="p.status === 'pending'"
                                                size="sm"
                                                variant="outline"
                                                @click="openPay(p)"
                                                ><CheckCircle2 /> Pagar</Button
                                            >
                                            <Button
                                                v-if="p.status === 'paid'"
                                                size="icon-sm"
                                                variant="ghost"
                                                title="Marcar como pendiente"
                                                @click="unpay(p)"
                                                ><RotateCcw
                                            /></Button>
                                            <Button
                                                v-if="p.status === 'pending'"
                                                size="icon-sm"
                                                variant="ghost"
                                                title="Editar"
                                                @click="openRow(p)"
                                                ><Pencil
                                            /></Button>
                                            <Button
                                                v-if="p.status !== 'paid'"
                                                size="icon-sm"
                                                variant="ghost"
                                                class="text-destructive hover:text-destructive"
                                                title="Eliminar"
                                                @click="toDelete = p"
                                                ><Trash2
                                            /></Button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!reservation.payments.length">
                                    <td
                                        colspan="5"
                                        class="px-3 py-8 text-center text-muted-foreground"
                                    >
                                        Sin pagos en el plan.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="overflow-hidden rounded-2xl border bg-card">
                    <header class="border-b px-4 py-3 font-semibold">
                        Unidades
                    </header>
                    <div class="divide-y">
                        <div
                            v-for="u in reservation.unit_rows"
                            :key="u.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
                        >
                            <div>
                                <p class="font-medium">{{ u.label }}</p>
                                <p
                                    v-if="u.detail"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ u.detail }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p
                                    v-if="u.price_uf < u.list_price_uf"
                                    class="text-xs text-muted-foreground tabular-nums line-through"
                                >
                                    {{ fmtUf(u.list_price_uf) }}
                                </p>
                                <p class="font-semibold tabular-nums">
                                    {{ fmtUf(u.price_uf) }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid gap-1 border-t bg-muted/30 px-4 py-3 text-sm"
                    >
                        <div
                            v-if="reservation.discount_uf > 0"
                            class="flex justify-between"
                        >
                            <span class="text-muted-foreground">Descuento</span
                            ><span class="tabular-nums"
                                >−{{ fmtUf(reservation.discount_uf) }}</span
                            >
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Pie</span
                            ><span class="tabular-nums">{{
                                fmtUf(reservation.pie_uf)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground"
                                >{{ financing[reservation.financing]
                                }}{{
                                    reservation.bank
                                        ? ` · ${reservation.bank}`
                                        : ''
                                }}</span
                            ><span class="tabular-nums">{{
                                fmtUf(reservation.credit_uf)
                            }}</span>
                        </div>
                        <div class="flex justify-between font-semibold">
                            <span>Total</span
                            ><span class="tabular-nums">{{
                                fmtUf(reservation.total_uf)
                            }}</span>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="flex flex-col gap-4">
                <section class="rounded-2xl border bg-card p-4 text-sm">
                    <h2 class="mb-2 font-semibold">Comprador</h2>
                    <template v-if="reservation.client">
                        <Link
                            :href="`/clients/${reservation.client.id}`"
                            class="font-medium text-primary hover:underline"
                            >{{ reservation.client.name }}</Link
                        >
                        <p
                            v-if="reservation.client.tax_id"
                            class="text-muted-foreground"
                        >
                            RUT {{ reservation.client.tax_id }}
                        </p>
                        <p
                            v-if="reservation.client.email"
                            class="text-muted-foreground"
                        >
                            {{ reservation.client.email }}
                        </p>
                        <p
                            v-if="reservation.client.phone"
                            class="text-muted-foreground"
                        >
                            {{ reservation.client.phone }}
                        </p>
                        <p
                            v-if="reservation.client.address"
                            class="text-muted-foreground"
                        >
                            {{ reservation.client.address
                            }}{{
                                reservation.client.commune
                                    ? `, ${reservation.client.commune}`
                                    : ''
                            }}
                        </p>
                    </template>
                    <Link
                        v-if="reservation.lead"
                        :href="`/leads/${reservation.lead.id}`"
                        class="mt-2 inline-block text-xs text-primary hover:underline"
                        >Ver prospecto</Link
                    >
                </section>
                <section
                    v-if="reservation.executive"
                    class="rounded-2xl border bg-card p-4 text-sm"
                >
                    <h2 class="mb-2 font-semibold">Ejecutivo/a</h2>
                    <p class="font-medium">{{ reservation.executive.name }}</p>
                    <p
                        v-if="reservation.executive.email"
                        class="text-muted-foreground"
                    >
                        {{ reservation.executive.email }}
                    </p>
                </section>
                <section
                    v-if="reservation.notes"
                    class="rounded-2xl border bg-card p-4 text-sm"
                >
                    <h2 class="mb-1 font-semibold">Notas</h2>
                    <p class="whitespace-pre-line text-muted-foreground">
                        {{ reservation.notes }}
                    </p>
                </section>
            </aside>
        </div>
    </div>

    <Dialog v-model:open="stepOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader
                ><DialogTitle>{{ stepCopy[step.status].title }}</DialogTitle
                ><DialogDescription>{{
                    stepCopy[step.status].text
                }}</DialogDescription></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="doStep">
                <FormField label="Fecha" for="st-date" :error="step.errors.date"
                    ><Input id="st-date" v-model="step.date" type="date"
                /></FormField>
                <FormField
                    v-if="step.status === 'cancelled'"
                    label="Motivo"
                    for="st-reason"
                    :error="step.errors.reason"
                    required
                    ><Input
                        id="st-reason"
                        v-model="step.reason"
                        placeholder="No aprobó el crédito, cambio de planes, compró en otro proyecto…"
                /></FormField>
                <DialogFooter
                    ><Button
                        type="submit"
                        :variant="
                            step.status === 'cancelled'
                                ? 'destructive'
                                : 'default'
                        "
                        :disabled="step.processing"
                        ><Spinner v-if="step.processing" />
                        {{ stepCopy[step.status].cta }}</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="editOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader
                ><DialogTitle>Editar reserva</DialogTitle></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="doEdit">
                <FormField
                    label="Plazo para promesar"
                    for="e-promise"
                    :error="edit.errors.promise_due_date"
                    ><Input
                        id="e-promise"
                        v-model="edit.promise_due_date"
                        type="date"
                /></FormField>
                <FormField
                    label="Financiamiento"
                    for="e-fin"
                    :error="edit.errors.financing"
                    ><NativeSelect id="e-fin" v-model="edit.financing"
                        ><option
                            v-for="(l, k) in financing"
                            :key="k"
                            :value="k"
                        >
                            {{ l }}
                        </option></NativeSelect
                    ></FormField
                >
                <FormField label="Banco" for="e-bank" :error="edit.errors.bank"
                    ><Input id="e-bank" v-model="edit.bank"
                /></FormField>
                <FormField
                    label="Notas"
                    for="e-notes"
                    :error="edit.errors.notes"
                    ><Textarea id="e-notes" v-model="edit.notes" rows="3"
                /></FormField>
                <DialogFooter
                    ><Button type="submit" :disabled="edit.processing"
                        ><Spinner v-if="edit.processing" /> Guardar</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="payOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader
                ><DialogTitle>Registrar pago</DialogTitle
                ><DialogDescription v-if="paying"
                    >{{ paying.label }} · {{ money(paying) }}</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="doPay">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Fecha de pago"
                        for="p-at"
                        :error="pay.errors.paid_at"
                        required
                        ><Input id="p-at" v-model="pay.paid_at" type="date"
                    /></FormField>
                    <FormField
                        label="Monto recibido ($)"
                        for="p-clp"
                        :error="pay.errors.paid_clp"
                        ><Input
                            id="p-clp"
                            v-model.number="pay.paid_clp"
                            type="number"
                            min="0"
                            step="1"
                    /></FormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Medio" for="p-method"
                        ><NativeSelect id="p-method" v-model="pay.method"
                            ><option v-for="m in methods" :key="m" :value="m">
                                {{ m }}
                            </option></NativeSelect
                        ></FormField
                    >
                    <FormField
                        label="Referencia"
                        for="p-ref"
                        :error="pay.errors.reference"
                        ><Input
                            id="p-ref"
                            v-model="pay.reference"
                            placeholder="N° de comprobante"
                    /></FormField>
                </div>
                <FormField label="Nota" for="p-notes" :error="pay.errors.notes"
                    ><Input id="p-notes" v-model="pay.notes"
                /></FormField>
                <DialogFooter
                    ><Button type="submit" :disabled="pay.processing"
                        ><Spinner v-if="pay.processing" /><CheckCircle2
                            v-else
                        />
                        Registrar pago</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="rowOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader
                ><DialogTitle>{{
                    editingRow ? 'Editar pago' : 'Agregar pago'
                }}</DialogTitle
                ><DialogDescription
                    >Ajusta el plan: cuotas extra, abonos, gastos operacionales
                    o el saldo.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="doRow">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Tipo"
                        for="w-kind"
                        :error="row.errors.kind"
                        ><NativeSelect id="w-kind" v-model="row.kind"
                            ><option
                                v-for="(l, k) in kinds"
                                :key="k"
                                :value="k"
                            >
                                {{ l }}
                            </option></NativeSelect
                        ></FormField
                    >
                    <FormField
                        label="Vencimiento"
                        for="w-due"
                        :error="row.errors.due_date"
                        ><Input id="w-due" v-model="row.due_date" type="date"
                    /></FormField>
                </div>
                <FormField
                    label="Concepto"
                    for="w-label"
                    :error="row.errors.label"
                    required
                    ><Input
                        id="w-label"
                        v-model="row.label"
                        placeholder="Ej.: Cuota extra del pie, abono, gastos operacionales"
                /></FormField>
                <div class="grid grid-cols-[1fr_110px] gap-4">
                    <FormField
                        label="Monto"
                        for="w-amount"
                        :error="row.errors.amount"
                        required
                        ><Input
                            id="w-amount"
                            v-model.number="row.amount"
                            type="number"
                            min="0"
                            :step="row.currency === 'UF' ? 0.01 : 1000"
                    /></FormField>
                    <FormField label="Moneda" for="w-cur"
                        ><NativeSelect id="w-cur" v-model="row.currency"
                            ><option value="UF">UF</option>
                            <option value="CLP">$</option></NativeSelect
                        ></FormField
                    >
                </div>
                <FormField label="Nota" for="w-notes" :error="row.errors.notes"
                    ><Input id="w-notes" v-model="row.notes"
                /></FormField>
                <DialogFooter
                    ><Button type="submit" :disabled="row.processing"
                        ><Spinner v-if="row.processing" /> Guardar</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="!!toDelete"
        title="Eliminar pago"
        :description="
            toDelete ? `Se quitará «${toDelete.label}» del plan de pagos.` : ''
        "
        confirm-label="Eliminar"
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="destroyRow"
    />
</template>
