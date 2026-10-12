<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { offsetLabel } from '@/lib/billingUi';

type Svc = {
    id: number; client_id: number; proposal_id: number | null; catalog_service_id: number | null; parent_id: number | null; name: string; description: string | null; project_address: string | null; modality: string; works_budget: number | null;
    billing_cycle: string; currency: string; price: number; start_date: string; end_date: string | null; auto_renew: boolean; status: string; billing_day: number | null;
    reminder_offsets: number[] | null; payment_link: string | null; internal_notes: string | null;
};

const props = defineProps<{
    service: Svc | null;
    defaults: { client_id: number | null; proposal_id: number | null } | null;
    clients: { id: number; name: string; kind?: string }[];
    proposals: { id: number; number: string; title: string; client_id: number | null }[];
    parents: { id: number; name: string; client_id: number }[];
    catalog: { id: number; name: string; description: string | null; billing: string; unit: string | null; currency: string; price: number }[];
    meta: { cycles: Record<string, string>; statuses: Record<string, string>; modalities: Record<string, string> };
    taxRate: number;
    defaultOffsets: number[];
}>();

type Hito = { concept: string; kind: 'honorarios' | 'obra'; amount: string; due_date: string };
const isEdit = computed(() => !!props.service);
defineOptions({ layout: { breadcrumbs: [{ title: 'Proyectos', href: '/contracts' }] } });

const s = props.service;
const form = useForm({
    client_id: (s?.client_id ?? props.defaults?.client_id ?? '') as number | string,
    proposal_id: (s?.proposal_id ?? props.defaults?.proposal_id ?? '') as number | string,
    catalog_service_id: (s?.catalog_service_id ?? '') as number | string,
    parent_id: (s?.parent_id ?? '') as number | string,
    name: s?.name ?? '',
    description: s?.description ?? '',
    billing_cycle: s?.billing_cycle ?? 'one_time',
    modality: s?.modality ?? 'honorarios',
    project_address: s?.project_address ?? '',
    works_budget: s?.works_budget ? String(s.works_budget) : '',
    currency: s?.currency ?? 'CLP',
    price: s ? String(s.price) : '',
    start_date: s?.start_date ?? new Date().toISOString().slice(0, 10),
    end_date: s?.end_date ?? '',
    auto_renew: s?.auto_renew ?? false,
    status: s?.status ?? 'active',
    billing_day: s?.billing_day ? String(s.billing_day) : '',
    custom_reminders: s?.reminder_offsets != null,
    reminder_offsets: (s?.reminder_offsets ?? [...props.defaultOffsets]) as number[],
    payment_link: s?.payment_link ?? '',
    internal_notes: s?.internal_notes ?? '',
    payment_plan: [] as Hito[],
});

const recurring = computed(() => form.billing_cycle !== 'one_time');
const clientProposals = computed(() => props.proposals.filter((p) => !form.client_id || !p.client_id || p.client_id === Number(form.client_id)));
const clientParents = computed(() => props.parents.filter((p) => p.client_id === Number(form.client_id) && p.id !== s?.id));

watch(() => form.client_id, () => {
    if (form.parent_id && !clientParents.value.some((p) => p.id === Number(form.parent_id))) form.parent_id = '';
    if (form.proposal_id && !clientProposals.value.some((p) => p.id === Number(form.proposal_id))) form.proposal_id = '';
});

/** Al elegir un servicio del catálogo se precargan nombre, descripción, precio y ciclo (editables). */
const fromCatalog = () => {
    const c = props.catalog.find((x) => x.id === Number(form.catalog_service_id));
    if (!c) return;
    form.name = form.name || c.name;
    form.description = form.description || (c.description ?? '');
    form.price = String(c.price);
    form.currency = c.currency;
    form.billing_cycle = c.billing === 'monthly' ? 'monthly' : 'one_time';
};

const offsetsText = computed({
    get: () => form.reminder_offsets.join(', '),
    set: (v: string) => (form.reminder_offsets = v.split(',').map((x) => parseInt(x.trim(), 10)).filter((n) => Number.isFinite(n))),
});

// ---- plan de pagos (solo al crear): cada hito genera un cobro por emitir ----
const addDays = (iso: string, days: number) => new Date(new Date(iso + 'T12:00:00').getTime() + days * 86400000).toISOString().slice(0, 10);
const roundAmount = (v: number) => (form.currency === 'UF' ? Math.round(v * 100) / 100 : Math.round(v));
const PRESETS: { label: string; parts: [string, number][] }[] = [
    { label: '100% al inicio', parts: [['Honorarios', 100]] },
    { label: '50% / 50%', parts: [['Anticipo al aceptar', 50], ['Entrega final', 50]] },
    { label: '40% / 30% / 30%', parts: [['Anticipo al aceptar', 40], ['Entrega de anteproyecto', 30], ['Entrega final', 30]] },
];
const applyPreset = (parts: [string, number][]) => {
    const total = Number(form.price);
    if (!Number.isFinite(total) || total <= 0) return;
    let acc = 0;
    const hon = parts.map(([concept, pct], i) => {
        const amount = i === parts.length - 1 ? roundAmount(total - acc) : roundAmount((total * pct) / 100);
        acc += amount;
        return { concept, kind: 'honorarios' as const, amount: String(amount), due_date: addDays(form.start_date, i * 21) };
    });
    form.payment_plan = [...hon, ...form.payment_plan.filter((h) => h.kind === 'obra')];
};
const addObra = () => form.payment_plan.push({ concept: 'Avance de obra', kind: 'obra', amount: '', due_date: addDays(form.start_date, 30) });
const removeHito = (i: number) => form.payment_plan.splice(i, 1);
const hitoErr = (i: number, k: string) => (form.errors as Record<string, string>)[`payment_plan.${i}.${k}`];
const planHonorarios = computed(() => form.payment_plan.filter((h) => h.kind === 'honorarios').reduce((a, h) => a + (Number(h.amount) || 0), 0));
const hasObra = computed(() => form.modality === 'honorarios_obra');

const back = () => window.history.back();
const submit = () => {
    form.transform((d) => ({ ...d, reminder_offsets: d.custom_reminders && recurring.value ? d.reminder_offsets : null, billing_day: recurring.value ? d.billing_day : '', payment_plan: isEdit.value ? [] : d.payment_plan.filter((h) => Number(h.amount) > 0), works_budget: hasObra.value ? d.works_budget : '' }));
    s ? form.put(`/contracts/${s.id}`) : form.post('/contracts');
};
</script>

<template>
    <Head :title="isEdit ? 'Editar proyecto' : 'Nuevo proyecto'" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="isEdit ? `Editar ${service?.name}` : 'Nuevo proyecto'" description="Registra lo que el cliente contrató: verá la descripción, las fechas y sus cobros (nunca tus costos ni gastos)." />

        <form class="grid max-w-4xl gap-6" @submit.prevent="submit">
            <div v-if="form.hasErrors" class="rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive" role="alert">
                <p class="font-medium">No se pudo guardar. Revisa estos campos:</p>
                <ul class="mt-1 list-disc pl-5"><li v-for="(m, k) in form.errors" :key="k">{{ m }}</li></ul>
            </div>
            <DataCard class="p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <p class="text-xs font-semibold tracking-wide text-muted-foreground uppercase sm:col-span-2">Contratación</p>
                    <FormField label="Cliente" for="client_id" required :error="form.errors.client_id">
                        <NativeSelect id="client_id" v-model="form.client_id"><option value="">Selecciona un cliente…</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}{{ c.kind === 'company' ? ' (empresa)' : '' }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Propuesta asociada" for="proposal_id" hint="Opcional: de qué propuesta nace este servicio." :error="form.errors.proposal_id">
                        <NativeSelect id="proposal_id" v-model="form.proposal_id"><option value="">Sin propuesta</option><option v-for="p in clientProposals" :key="p.id" :value="p.id">{{ p.number }} · {{ p.title }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Servicio del catálogo" for="catalog" hint="Precarga nombre, descripción y tarifa (puedes cambiarlos).">
                        <NativeSelect id="catalog" v-model="form.catalog_service_id" @update:model-value="fromCatalog"><option value="">Servicio a medida</option><option v-for="c in catalog" :key="c.id" :value="c.id">{{ c.name }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Asociado a otro proyecto" for="parent_id" hint="Ej.: una obra complementaria a este proyecto." :error="form.errors.parent_id">
                        <NativeSelect id="parent_id" v-model="form.parent_id" :disabled="!form.client_id"><option value="">Es un proyecto principal</option><option v-for="p in clientParents" :key="p.id" :value="p.id">{{ p.name }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Nombre del proyecto o servicio" for="name" required class="sm:col-span-2" :error="form.errors.name">
                        <Input id="name" v-model="form.name" placeholder="Regularización vivienda · Los Aromos 123" />
                    </FormField>
                    <FormField label="Modalidad" for="modality" :error="form.errors.modality" hint="Solo honorarios (diseño y gestión) o honorarios más la obra completa.">
                        <NativeSelect id="modality" v-model="form.modality"><option v-for="(l, k) in meta.modalities" :key="k" :value="k">{{ l }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Dirección del proyecto" for="project_address" :error="form.errors.project_address">
                        <Input id="project_address" v-model="form.project_address" placeholder="Calle, número, comuna" />
                    </FormField>
                    <FormField label="Descripción para el cliente" for="description" class="sm:col-span-2" hint="Es lo que verá en su portal." :error="form.errors.description">
                        <Textarea id="description" v-model="form.description" rows="3" placeholder="Qué incluye el servicio, en lenguaje simple." />
                    </FormField>
                </div>
            </DataCard>

            <DataCard class="p-6">
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <p class="text-xs font-semibold tracking-wide text-muted-foreground uppercase sm:col-span-2 lg:col-span-3">Vigencia y cobro</p>
                    <FormField label="Ciclo de cobro" for="cycle" required :error="form.errors.billing_cycle">
                        <NativeSelect id="cycle" v-model="form.billing_cycle"><option v-for="(l, k) in meta.cycles" :key="k" :value="k">{{ l }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Moneda" for="currency" :error="form.errors.currency">
                        <NativeSelect id="currency" v-model="form.currency"><option value="CLP">Pesos (CLP)</option><option value="UF">UF</option></NativeSelect>
                    </FormField>
                    <FormField :label="recurring ? 'Valor por período' : 'Honorarios totales'" for="price" required hint="Monto bruto de la boleta de honorarios (la retención se calcula en cada cobro)." :error="form.errors.price">
                        <Input id="price" v-model="form.price" type="number" min="0" :step="form.currency === 'UF' ? 0.01 : 1000" />
                    </FormField>
                    <FormField label="Inicio" for="start" required :error="form.errors.start_date"><Input id="start" v-model="form.start_date" type="date" /></FormField>
                    <FormField label="Término" for="end" hint="Vacío = sin fecha de término." :error="form.errors.end_date"><Input id="end" v-model="form.end_date" type="date" /></FormField>
                    <FormField label="Estado" for="status" :error="form.errors.status">
                        <NativeSelect id="status" v-model="form.status"><option v-for="(l, k) in meta.statuses" :key="k" :value="k">{{ l }}</option></NativeSelect>
                    </FormField>
 <FormField v-if="hasObra" label="Presupuesto de obra (informativo)" for="works" hint="Estimado de la obra completa; sus pagos se agregan como hitos de obra." :error="form.errors.works_budget">
                        <Input id="works" v-model="form.works_budget" type="number" min="0" :step="form.currency === 'UF' ? 0.01 : 1000" />
                    </FormField>
                    <label class="flex items-center gap-3 rounded-xl border p-3 text-sm sm:col-span-2 lg:col-span-3">
                        <Switch :model-value="form.auto_renew" @update:model-value="(v: boolean) => (form.auto_renew = v)" />
                        <span><strong>Renovación automática</strong><span class="block text-xs text-muted-foreground">Al llegar la fecha de término se renueva sola por otro período y sigue generando cobros.</span></span>
                    </label>
                    <FormField v-if="recurring" label="Día de cobro del mes" for="bday" hint="Vacío = el día de inicio." :error="form.errors.billing_day">
                        <Input id="bday" v-model="form.billing_day" type="number" min="1" max="31" placeholder="Ej: 5" />
                    </FormField>
                    <FormField label="Enlace de pago" for="plink" class="sm:col-span-2" hint="Opcional: Flow, Webpay, MercadoPago… se muestra en el portal y en los correos." :error="form.errors.payment_link">
                        <Input id="plink" v-model="form.payment_link" type="url" placeholder="https://" />
                    </FormField>
                </div>

                <div v-if="recurring" class="mt-5 rounded-xl border p-4">
                    <label class="flex items-center gap-3 text-sm">
                        <Switch :model-value="form.custom_reminders" @update:model-value="(v: boolean) => (form.custom_reminders = v)" />
                        <span><strong>Recordatorios de pago personalizados</strong><span class="block text-xs text-muted-foreground">Si no, se usan los de la cobranza: {{ defaultOffsets.map(offsetLabel).join(' · ') }}.</span></span>
                    </label>
                    <div v-if="form.custom_reminders" class="mt-3 grid gap-2 sm:max-w-md">
                        <FormField label="Días respecto del vencimiento" for="offsets" hint="Separados por coma. Negativo = antes (ej: -7, -1, 0, 5)." :error="form.errors.reminder_offsets"><Input id="offsets" v-model="offsetsText" /></FormField>
                    </div>
                </div>
            </DataCard>

            <DataCard v-if="!isEdit" class="p-6">
                <div class="grid gap-4">
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Plan de pagos</p>
                        <p class="mt-1 text-xs text-muted-foreground">Opcional: define los hitos de cobro. Cada uno queda como un cobro «por emitir» (adjuntas la boleta cuando la emitas en el SII).</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="pr in PRESETS" :key="pr.label" type="button" size="sm" variant="outline" :disabled="!Number(form.price)" @click="applyPreset(pr.parts)">{{ pr.label }}</Button>
                        <Button v-if="hasObra" type="button" size="sm" variant="outline" @click="addObra">+ Hito de obra</Button>
                        <Button v-if="form.payment_plan.length" type="button" size="sm" variant="ghost" @click="form.payment_plan = []">Limpiar</Button>
                    </div>
                    <p v-if="!Number(form.price)" class="text-xs text-muted-foreground">Ingresa primero los honorarios totales para usar un plan sugerido.</p>
                    <div v-for="(h, i) in form.payment_plan" :key="i" class="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_8rem_9rem_auto] sm:items-end">
                        <FormField :label="h.kind === 'obra' ? 'Hito de obra' : 'Hito de honorarios'" :for="`h-c-${i}`" :error="hitoErr(i, 'concept')"><Input :id="`h-c-${i}`" v-model="h.concept" /></FormField>
                        <FormField label="Monto" :for="`h-a-${i}`" :error="hitoErr(i, 'amount')"><Input :id="`h-a-${i}`" v-model="h.amount" type="number" min="0" :step="form.currency === 'UF' ? 0.01 : 1" /></FormField>
                        <FormField label="Vence" :for="`h-d-${i}`" :error="hitoErr(i, 'due_date')"><Input :id="`h-d-${i}`" v-model="h.due_date" type="date" /></FormField>
                        <Button type="button" size="sm" variant="ghost" class="text-destructive" @click="removeHito(i)">Quitar</Button>
                    </div>
                    <p v-if="planHonorarios && Number(form.price) && planHonorarios !== Number(form.price)" class="text-xs text-amber-700 dark:text-amber-300">Los hitos de honorarios suman {{ planHonorarios }} y los honorarios totales son {{ form.price }}.</p>
                </div>
            </DataCard>

            <DataCard class="p-6">
                <FormField label="Notas internas" for="notes" hint="Solo las ve el equipo." :error="form.errors.internal_notes">
                    <Textarea id="notes" v-model="form.internal_notes" rows="3" />
                </FormField>
            </DataCard>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="outline" @click="back">Cancelar</Button>
                <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> {{ isEdit ? 'Guardar cambios' : 'Registrar servicio' }}</Button>
            </div>
        </form>
    </div>
</template>
