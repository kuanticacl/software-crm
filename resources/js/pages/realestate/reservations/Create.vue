<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';
import { computed } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { clp, pct, r2, toClp, uf as fmtUf } from '@/lib/realEstate';

type Client = {
    id: number;
    name: string;
    tax_id: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    commune: string | null;
};

const props = defineProps<{
    quote: {
        id: number;
        number: string;
        project: { id: number; name: string; promise_days: number };
        items: {
            label: string;
            price_uf: number;
            unit_status: string | null;
        }[];
        total_uf: number;
        pie_pct: number;
        pie_uf: number;
        credit_uf: number;
        reservation_amount: number;
        pie_installments: number;
        buyer: { name?: string; rut?: string; email?: string; phone?: string };
        lead: { id: number; name: string } | null;
    };
    client: Client | null;
    defaults: {
        reserved_at: string;
        promise_due_date: string;
        first_installment_date: string;
    };
    uf: { value: number; date: string } | null;
    financing: Record<string, string>;
    methods: string[];
    installmentUf: number;
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Reservas', href: '/reservations' },
            { title: 'Nueva reserva', href: '#' },
        ],
    },
});

const form = useForm({
    quote_id: props.quote.id,
    client_id: props.client?.id ?? null,
    buyer: {
        name:
            props.client?.name ??
            props.quote.buyer.name ??
            props.quote.lead?.name ??
            '',
        rut: props.client?.tax_id ?? props.quote.buyer.rut ?? '',
        email: props.client?.email ?? props.quote.buyer.email ?? '',
        phone: props.client?.phone ?? props.quote.buyer.phone ?? '',
        address: props.client?.address ?? '',
        commune: props.client?.commune ?? '',
    },
    reserved_at: props.defaults.reserved_at,
    promise_due_date: props.defaults.promise_due_date,
    reservation_amount: Number(props.quote.reservation_amount ?? 0),
    reservation_paid: true,
    reservation_method: 'Transferencia',
    reservation_reference: '',
    pie_installments: Number(props.quote.pie_installments ?? 0),
    first_installment_date: props.defaults.first_installment_date,
    financing: 'credito',
    bank: '',
    notes: '',
    notify: true,
});

const blocked = computed(() =>
    props.quote.items.filter(
        (i) => i.unit_status && i.unit_status !== 'disponible',
    ),
);
const ufValue = computed(() => props.uf?.value ?? 0);
const reservationUf = computed(() =>
    ufValue.value > 0
        ? r2(Number(form.reservation_amount || 0) / ufValue.value)
        : 0,
);
const pieRest = computed(() =>
    Math.max(0, r2(props.quote.pie_uf - reservationUf.value)),
);
const installment = computed(() =>
    form.pie_installments > 0
        ? r2(pieRest.value / form.pie_installments)
        : pieRest.value,
);
const lastDue = computed(() => {
    if (!form.first_installment_date || form.pie_installments < 1) return null;
    const d = new Date(`${form.first_installment_date}T12:00:00`);
    d.setMonth(d.getMonth() + form.pie_installments - 1);
    return d.toLocaleDateString('es-CL', { month: 'long', year: 'numeric' });
});

// Errores del servicio que no corresponden a un campo del formulario (unidad tomada, sin UF).
const extraErrors = computed(() => {
    const e = form.errors as Record<string, string | undefined>;
    return e.items || e.uf;
});

const submit = () => form.post('/reservations');
</script>

<template>
    <Head title="Nueva reserva" />

    <form
        class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Reservar · {{ quote.project.name }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Desde la cotización
                <Link
                    :href="`/quotes/${quote.id}`"
                    class="text-primary hover:underline"
                    >{{ quote.number }}</Link
                >. Al guardar, las unidades quedan reservadas y se genera el
                plan de pagos.
            </p>
        </div>

        <div
            v-if="blocked.length"
            class="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm"
        >
            {{ blocked.map((i) => i.label).join(', ') }} ya no
            {{ blocked.length > 1 ? 'están disponibles' : 'está disponible' }}.
            Duplica la cotización con otras unidades.
        </div>
        <InputError :message="extraErrors" />

        <div class="grid gap-6 lg:grid-cols-[1fr_340px]">
            <div class="flex flex-col gap-6">
                <section class="grid gap-4 rounded-2xl border bg-card p-4">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Comprador</h2>
                        <span
                            v-if="client"
                            class="text-xs text-muted-foreground"
                            >Cliente existente: {{ client.name }}</span
                        >
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Nombre completo"
                            for="b-name"
                            :error="form.errors['buyer.name']"
                            required
                            ><Input
                                id="b-name"
                                v-model="form.buyer.name"
                                :disabled="!!form.client_id"
                        /></FormField>
                        <FormField
                            label="RUT"
                            for="b-rut"
                            :error="form.errors['buyer.rut']"
                            ><Input
                                id="b-rut"
                                v-model="form.buyer.rut"
                                placeholder="12.345.678-9"
                                :disabled="!!form.client_id"
                        /></FormField>
                        <FormField
                            label="Correo"
                            for="b-email"
                            :error="form.errors['buyer.email']"
                            ><Input
                                id="b-email"
                                v-model="form.buyer.email"
                                type="email"
                                :disabled="!!form.client_id"
                        /></FormField>
                        <FormField
                            label="Teléfono"
                            for="b-phone"
                            :error="form.errors['buyer.phone']"
                            ><Input
                                id="b-phone"
                                v-model="form.buyer.phone"
                                :disabled="!!form.client_id"
                        /></FormField>
                        <FormField
                            label="Dirección"
                            for="b-address"
                            :error="form.errors['buyer.address']"
                            ><Input
                                id="b-address"
                                v-model="form.buyer.address"
                                :disabled="!!form.client_id"
                        /></FormField>
                        <FormField
                            label="Comuna"
                            for="b-commune"
                            :error="form.errors['buyer.commune']"
                            ><Input
                                id="b-commune"
                                v-model="form.buyer.commune"
                                :disabled="!!form.client_id"
                        /></FormField>
                    </div>
                    <button
                        v-if="client"
                        type="button"
                        class="justify-self-start text-xs text-primary hover:underline"
                        @click="
                            form.client_id = form.client_id ? null : client.id
                        "
                    >
                        {{
                            form.client_id
                                ? 'Usar otro comprador (crear o buscar por RUT)'
                                : `Volver a usar ${client.name}`
                        }}
                    </button>
                    <p v-else class="text-xs text-muted-foreground">
                        Si el RUT ya existe en Clientes se reutiliza; si no, se
                        crea el cliente.
                    </p>
                </section>

                <section class="grid gap-4 rounded-2xl border bg-card p-4">
                    <h2 class="font-semibold">Reserva</h2>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField
                            label="Fecha de reserva"
                            for="r-at"
                            :error="form.errors.reserved_at"
                            required
                            ><Input
                                id="r-at"
                                v-model="form.reserved_at"
                                type="date"
                        /></FormField>
                        <FormField
                            label="Monto de la reserva ($)"
                            for="r-amount"
                            :error="form.errors.reservation_amount"
                            :hint="uf ? `≈ ${fmtUf(reservationUf)}` : undefined"
                            required
                            ><Input
                                id="r-amount"
                                v-model.number="form.reservation_amount"
                                type="number"
                                min="0"
                                step="1000"
                        /></FormField>
                        <FormField
                            label="Plazo para promesar"
                            for="r-promise"
                            :error="form.errors.promise_due_date"
                            :hint="`Por defecto ${quote.project.promise_days} días`"
                            ><Input
                                id="r-promise"
                                v-model="form.promise_due_date"
                                type="date"
                        /></FormField>
                    </div>
                    <label
                        class="flex items-center justify-between gap-3 rounded-xl bg-muted/40 px-3 py-2 text-sm"
                        ><span>La reserva ya fue pagada</span
                        ><Switch
                            :model-value="form.reservation_paid"
                            @update:model-value="
                                (v: boolean) => (form.reservation_paid = v)
                            "
                    /></label>
                    <div
                        v-if="form.reservation_paid"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <FormField label="Medio de pago" for="r-method"
                            ><NativeSelect
                                id="r-method"
                                v-model="form.reservation_method"
                                ><option
                                    v-for="m in methods"
                                    :key="m"
                                    :value="m"
                                >
                                    {{ m }}
                                </option></NativeSelect
                            ></FormField
                        >
                        <FormField
                            label="Comprobante / referencia"
                            for="r-ref"
                            :error="form.errors.reservation_reference"
                            ><Input
                                id="r-ref"
                                v-model="form.reservation_reference"
                                placeholder="N° de transferencia, voucher…"
                        /></FormField>
                    </div>
                </section>

                <section class="grid gap-4 rounded-2xl border bg-card p-4">
                    <h2 class="font-semibold">Plan de pagos del pie</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Cuotas del pie"
                            for="r-inst"
                            :error="form.errors.pie_installments"
                            hint="0 = pie al contado antes de la promesa"
                            ><Input
                                id="r-inst"
                                v-model.number="form.pie_installments"
                                type="number"
                                min="0"
                                max="120"
                        /></FormField>
                        <FormField
                            v-if="form.pie_installments > 0"
                            label="Primera cuota"
                            for="r-first"
                            :error="form.errors.first_installment_date"
                            ><Input
                                id="r-first"
                                v-model="form.first_installment_date"
                                type="date"
                        /></FormField>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField
                            label="Financiamiento del saldo"
                            for="r-fin"
                            :error="form.errors.financing"
                            ><NativeSelect id="r-fin" v-model="form.financing"
                                ><option
                                    v-for="(l, k) in financing"
                                    :key="k"
                                    :value="k"
                                >
                                    {{ l }}
                                </option></NativeSelect
                            ></FormField
                        >
                        <FormField
                            v-if="['credito', 'mixto'].includes(form.financing)"
                            label="Banco"
                            for="r-bank"
                            :error="form.errors.bank"
                            ><Input
                                id="r-bank"
                                v-model="form.bank"
                                placeholder="Banco con el que tramita el crédito"
                        /></FormField>
                    </div>
                    <FormField
                        label="Notas"
                        for="r-notes"
                        :error="form.errors.notes"
                        ><Textarea id="r-notes" v-model="form.notes" rows="3"
                    /></FormField>
                </section>
            </div>

            <aside class="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
                <section class="rounded-2xl border bg-card p-4 text-sm">
                    <h2 class="mb-2 font-semibold">Unidades</h2>
                    <ul class="grid gap-1.5">
                        <li
                            v-for="(i, n) in quote.items"
                            :key="n"
                            class="flex justify-between gap-2"
                        >
                            <span>{{ i.label }}</span
                            ><span class="tabular-nums">{{
                                fmtUf(i.price_uf)
                            }}</span>
                        </li>
                    </ul>
                    <div class="mt-3 grid gap-1 border-t pt-3">
                        <div class="flex justify-between font-semibold">
                            <span>Total</span
                            ><span class="tabular-nums">{{
                                fmtUf(quote.total_uf)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground"
                                >Pie ({{ pct(quote.pie_pct) }})</span
                            ><span class="tabular-nums">{{
                                fmtUf(quote.pie_uf)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">− Reserva</span
                            ><span class="tabular-nums">{{
                                fmtUf(reservationUf)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground"
                                >Saldo del pie</span
                            ><span class="tabular-nums">{{
                                fmtUf(pieRest)
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">{{
                                financing[form.financing]
                            }}</span
                            ><span class="tabular-nums">{{
                                fmtUf(quote.credit_uf)
                            }}</span>
                        </div>
                    </div>
                    <div class="mt-3 rounded-xl bg-primary/10 p-3">
                        <p
                            v-if="form.pie_installments > 0"
                            class="font-semibold text-primary"
                        >
                            {{ form.pie_installments }} cuotas de
                            {{ fmtUf(installment) }}
                        </p>
                        <p v-else class="font-semibold text-primary">
                            Pie al contado: {{ fmtUf(pieRest) }}
                        </p>
                        <p v-if="uf" class="text-xs text-muted-foreground">
                            ≈ {{ clp(toClp(installment, uf.value)) }} con UF
                            {{ clp(uf.value) }}
                        </p>
                        <p v-if="lastDue" class="text-xs text-muted-foreground">
                            Última cuota en {{ lastDue }}
                        </p>
                    </div>
                    <p v-if="!uf" class="mt-2 text-xs text-destructive">
                        No hay valor UF disponible hoy: la reserva no se puede
                        registrar hasta obtenerlo.
                    </p>
                </section>

                <label
                    class="flex items-center justify-between gap-3 rounded-2xl border bg-card p-4 text-sm"
                    ><span>Enviar confirmación por correo al comprador</span
                    ><Switch
                        :model-value="form.notify"
                        @update:model-value="(v: boolean) => (form.notify = v)"
                /></label>

                <Button
                    type="submit"
                    size="lg"
                    :disabled="form.processing || blocked.length > 0"
                    ><Spinner v-if="form.processing" /><KeyRound v-else />
                    Confirmar reserva</Button
                >
            </aside>
        </div>
    </form>
</template>
