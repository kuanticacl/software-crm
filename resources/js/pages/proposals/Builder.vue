<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ArrowDown, ArrowLeft, ArrowUp, Building2, Eye, FileText, Package, Plus, Receipt, Repeat, Save, Search, Sparkles, StickyNote, Trash2, Wand2 } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FormField from '@/components/FormField.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { HttpError, sendJson } from '@/lib/http';
import { formatAmount, formatMoney, formatUfValue } from '@/lib/leadUi';
import { stripHtml } from '@/lib/richText';
import { formatRut } from '@/lib/rut';
import { cn } from '@/lib/utils';
import { edit, index, preview, store, update } from '@/routes/proposals';
import { index as aiIndex } from '@/routes/ai';
import { index as servicesIndex } from '@/routes/services';
import type { CatalogService, ProposalItemInput, Recipient } from '@/types';

type Section = { title: string; body: string };
type TaxMode = 'iva' | 'exenta' | 'honorarios' | 'none';
type Initial = {
    title: string; currency: 'UF' | 'CLP'; client_id: number | null; lead_id: number | null; recipient: Recipient; sections: Section[]; valid_until: string | null; contract_months: number | null;
    discount_type: 'percent' | 'amount'; discount_value: number; tax_mode: TaxMode; tax_rate: number; internal_notes: string | null;
    items: Omit<ProposalItemInput, 'key'>[];
};

const props = defineProps<{
    proposal: { id: number; number: string; status: string; is_final: boolean } | null;
    initial: Initial;
    services: CatalogService[];
    uf: { value: number; date: string } | null;
    taxModes: Record<TaxMode, string>;
    taxRates: Record<TaxMode, number>;
    clients: { id: number; name: string; recipient: Recipient }[];
    lead: { id: number; first_name: string; last_name: string | null; company: string | null } | null;
    ai: { enabled: boolean; can_configure: boolean };
    can: { services: boolean };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Propuestas', href: index() }] } });

const uid = () => Math.random().toString(36).slice(2, 9);
const i0 = props.initial;

const form = reactive({
    title: i0.title,
    currency: (i0.currency ?? 'UF') as 'UF' | 'CLP',
    client_id: i0.client_id as number | null,
    lead_id: i0.lead_id as number | null,
    valid_until: i0.valid_until ?? '',
    contract_months: (i0.contract_months ?? undefined) as number | undefined,
    discount_type: i0.discount_type,
    discount_value: i0.discount_value,
    tax_mode: i0.tax_mode,
    tax_rate: Number(i0.tax_rate),
    internal_notes: i0.internal_notes ?? '',
});
const blank = (r: Recipient) => Object.fromEntries((['company', 'legal_name', 'rut', 'activity', 'address', 'contact_name', 'contact_role', 'email', 'phone'] as const).map((k) => [k, r[k] ?? ''])) as Record<keyof Recipient, string>;
const recipient = reactive<Record<keyof Recipient, string>>(blank(i0.recipient));
const sections = ref<Section[]>(JSON.parse(JSON.stringify(i0.sections)));
const items = ref<ProposalItemInput[]>(i0.items.map((i) => ({ ...i, key: uid(), description: i.description ?? '', deliverables: i.deliverables ?? [] })));

const locked = computed(() => !!props.proposal?.is_final);
const saving = ref(false);
const errorMsg = ref('');

// ---------------------------------------------------------------- cliente
const onClient = () => {
    const c = props.clients.find((x) => x.id === form.client_id);
    if (c) Object.assign(recipient, blank(c.recipient));
};

// ---------------------------------------------------------------- servicios
const catalogQ = ref('');
const catalogOpen = ref(false);
const catalogGroups = computed(() => {
    const t = catalogQ.value.trim().toLowerCase();
    const m = new Map<string, CatalogService[]>();
    props.services.filter((s) => !t || `${s.name} ${s.category}`.toLowerCase().includes(t)).forEach((s) => m.set(s.category, [...(m.get(s.category) ?? []), s]));
    return [...m.entries()];
});
const decimals = computed(() => (form.currency === 'UF' ? 2 : 0));
const toCurrency = (price: number, from: 'UF' | 'CLP') => {
    if (from === form.currency || !props.uf) return price;
    const v = from === 'UF' ? price * props.uf.value : price / props.uf.value;
    return Number(v.toFixed(decimals.value));
};
// Al cambiar de moneda, los valores ya cargados se convierten con la UF de hoy (sin UF no se puede convertir: se revierte).
watch(
    () => form.currency,
    (to, from) => {
        if (!from || to === from || !items.value.length) return;
        if (!props.uf) {
            form.currency = from;
            toast.error('No hay valor de UF disponible para convertir los precios. Intenta de nuevo en unos minutos.');
            return;
        }
        const dec = to === 'UF' ? 2 : 0;
        const k = props.uf.value;
        items.value.forEach((it) => (it.unit_price = Number((from === 'UF' ? it.unit_price * k : it.unit_price / k).toFixed(dec))));
        if (form.discount_type === 'amount' && form.discount_value) {
            form.discount_value = Number((from === 'UF' ? form.discount_value * k : form.discount_value / k).toFixed(dec));
        }
        toast.success(`Precios convertidos a ${to} con la UF de hoy`);
    },
);
const addService = (s: CatalogService) => {
    items.value.push({ key: uid(), service_id: s.id, name: s.name, description: s.description ?? '', deliverables: [...(s.deliverables ?? [])], billing: s.billing, unit: s.unit, quantity: 1, unit_price: toCurrency(s.price, s.currency), discount_pct: 0 });
    toast.success(`«${s.name}» agregado`);
};
const addCustom = () => items.value.push({ key: uid(), service_id: null, name: '', description: '', deliverables: [], billing: 'one_time', unit: 'servicio', quantity: 1, unit_price: 0, discount_pct: 0 });
const removeItem = (k: string) => (items.value = items.value.filter((i) => i.key !== k));
const move = (idx: number, d: number) => {
    const j = idx + d;
    if (j < 0 || j >= items.value.length) return;
    const a = [...items.value];
    [a[idx], a[j]] = [a[j], a[idx]];
    items.value = a;
};
const delivText = (i: ProposalItemInput) => i.deliverables.join('\n');
const setDeliv = (i: ProposalItemInput, v: string) => (i.deliverables = v.split('\n').map((x) => x.trim()).filter(Boolean));

// ---------------------------------------------------------------- totales (el servidor recalcula al guardar)
const totals = computed(() => {
    const d = decimals.value;
    const r = (n: number) => Number(n.toFixed(d));
    let one = 0;
    let mon = 0;
    for (const i of items.value) {
        const line = r(i.quantity * i.unit_price * (1 - (i.discount_pct || 0) / 100));
        i.billing === 'monthly' ? (mon += line) : (one += line);
    }
    const m = Math.max(1, form.contract_months || 1);
    const base = one + mon * m;
    const discount = form.discount_type === 'amount' ? Math.min(form.discount_value || 0, base) : r((base * Math.min(100, form.discount_value || 0)) / 100);
    const f = base > 0 ? (base - discount) / base : 1;
    const tOne = r(one * f);
    const tMon = r(mon * f);
    const net = r(tOne + tMon * m);
    const rate = form.tax_mode === 'iva' || form.tax_mode === 'honorarios' ? form.tax_rate || 0 : 0;
    // Honorarios: el neto es lo que recibe la empresa; la boleta va por el bruto y la retención se suma.
    const tax = form.tax_mode === 'honorarios' ? (rate > 0 && rate < 100 ? r(net / (1 - rate / 100) - net) : 0) : r((net * rate) / 100);
    return { one: tOne, monthly: tMon, months: m, discount: r(discount), net, tax, gross: r(net + tax), hasMonthly: mon > 0 };
});
const taxLabels = computed(() => {
    const rate = String(Number(form.tax_rate || 0)).replace('.', ',');
    if (form.tax_mode === 'iva') return { net: 'Total neto', tax: `IVA ${rate}%`, gross: 'Total con IVA' };
    if (form.tax_mode === 'exenta') return { net: 'Total', tax: null, gross: 'Total (exento de IVA)' };
    if (form.tax_mode === 'none') return { net: 'Total', tax: null, gross: 'Total' };
    return { net: 'Honorarios netos', tax: `Retención boleta de honorarios ${rate}%`, gross: 'Total boleta de honorarios' };
});
const onTaxMode = () => (form.tax_rate = props.taxRates[form.tax_mode] ?? 0);
const grossClp = computed(() => (form.currency === 'UF' && props.uf ? Math.round(totals.value.gross * props.uf.value) : totals.value.gross));

// ---------------------------------------------------------------- contenido
const addSection = () => sections.value.push({ title: 'Nueva sección', body: '' });
const removeSection = (i: number) => sections.value.splice(i, 1);
const moveSection = (i: number, d: number) => {
    const j = i + d;
    if (j < 0 || j >= sections.value.length) return;
    [sections.value[i], sections.value[j]] = [sections.value[j], sections.value[i]];
};

// ---------------------------------------------------------------- vista previa en vivo
const payload = () => ({
    ...form,
    valid_until: form.valid_until || null,
    contract_months: form.contract_months || null,
    internal_notes: form.internal_notes || null,
    recipient: { ...recipient },
    sections: sections.value,
    items: items.value.map(({ key, ...rest }) => rest),
});
const html = ref('');
let timer: ReturnType<typeof setTimeout>;
const renderPreview = async () => {
    try {
        const res = await fetch(preview().url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '') },
            body: JSON.stringify(payload()),
        });
        if (res.ok) html.value = await res.text();
    } catch {
        /* se reintenta con el siguiente cambio */
    }
};
watch([form, recipient, sections, items], () => {
    clearTimeout(timer);
    timer = setTimeout(renderPreview, 500);
}, { deep: true, immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

// ---------------------------------------------------------------- guardar
const snap = () => JSON.stringify([form, recipient, sections.value, items.value]);
const baseline = ref(snap());
const dirty = computed(() => snap() !== baseline.value);

const save = async () => {
    saving.value = true;
    errorMsg.value = '';
    try {
        if (props.proposal) {
            await sendJson('PUT', update(props.proposal.id).url, payload());
            baseline.value = snap();
            toast.success('Propuesta guardada');
        } else {
            const res = await sendJson<{ id: number }>('POST', store().url, payload());
            baseline.value = snap();
            toast.success('Propuesta creada');
            router.visit(edit(res.id).url, { replace: true });
        }
    } catch (e) {
        errorMsg.value = e instanceof HttpError ? (Object.values(e.fieldErrors)[0] ?? e.body?.message ?? 'No se pudo guardar.') : 'No se pudo guardar.';
        toast.error(errorMsg.value);
    } finally {
        saving.value = false;
    }
};
useEventListener(window, 'keydown', (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (!locked.value) save();
    }
});
useEventListener(window, 'beforeunload', (e: BeforeUnloadEvent) => {
    if (dirty.value) e.preventDefault();
});

// ---------------------------------------------------------------- IA
const aiBusy = ref<string | null>(null);
const aiCall = async <T,>(key: string, url: string, body: unknown): Promise<T | null> => {
    aiBusy.value = key;
    try {
        return await sendJson<T>('POST', url, body);
    } catch (e) {
        toast.error(e instanceof HttpError ? (Object.values(e.fieldErrors)[0] ?? e.body?.message ?? 'La IA no pudo responder.') : 'La IA no pudo responder.');
        return null;
    } finally {
        aiBusy.value = null;
    }
};
const context = () => ({ lead_id: form.lead_id, recipient: { ...recipient }, items: items.value.map((i) => ({ name: i.name, billing: i.billing })) });

const draftOpen = ref(false);
const draft = reactive({ brief: '', tone: 'Cercano y profesional' });
const runDraft = async () => {
    const r = await aiCall<{ sections: Section[]; title: string }>('draft', '/proposals/ai/draft', { ...context(), ...draft, title: form.title });
    if (!r) return;
    sections.value = r.sections;
    draftOpen.value = false;
    toast.success('Propuesta redactada. Revisa y ajusta lo que necesites.');
};

const suggestOpen = ref(false);
const suggestBrief = ref('');
const suggestions = ref<{ service_id: number; reason: string }[]>([]);
const runSuggest = async () => {
    const r = await aiCall<{ suggestions: { service_id: number; reason: string }[] }>('suggest', '/proposals/ai/suggest', { ...context(), brief: suggestBrief.value });
    if (r) suggestions.value = r.suggestions;
};
const serviceById = (id: number) => props.services.find((s) => s.id === id);

const improve = async (idx: number, mode: string, instruction = '') => {
    const s = sections.value[idx];
    const r = await aiCall<{ text: string }>(`imp-${idx}`, '/proposals/ai/improve', { text: s.body, title: s.title, mode, instruction, recipient: { ...recipient } });
    if (r) s.body = r.text;
};
const writeItem = async (i: ProposalItemInput) => {
    if (!i.name.trim()) return toast.info('Primero escribe el nombre del servicio.');
    const r = await aiCall<{ description: string; deliverables: string[] }>(`it-${i.key}`, '/proposals/ai/service', { name: i.name, billing: i.billing, hint: i.description });
    if (r) {
        i.description = r.description;
        i.deliverables = r.deliverables;
    }
};
const customPrompt = ref<{ idx: number; text: string } | null>(null);
const money = (n: number) => formatAmount(n, form.currency);
</script>

<template>
    <Head :title="proposal ? `Editar ${proposal.number}` : 'Nueva propuesta'" />

    <div class="flex h-[calc(100svh-4.5rem)] min-h-[600px] flex-col">
        <!-- Barra superior -->
        <div class="flex flex-wrap items-center gap-2 border-b bg-card px-3 py-2">
            <Button variant="ghost" size="icon-sm" as-child title="Volver"><Link :href="proposal ? `/proposals/${proposal.id}` : index()"><ArrowLeft /></Link></Button>
            <Input v-model="form.title" :disabled="locked" placeholder="Título de la propuesta" class="h-9 max-w-md min-w-56 flex-1 font-semibold" />
            <span v-if="proposal" class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{{ proposal.number }}</span>
            <span v-if="dirty" class="rounded-full bg-[#FFA165]/20 px-2 py-0.5 text-[11px] font-medium text-[#9A4B00]">Cambios sin guardar</span>
            <div class="ml-auto flex items-center gap-2">
                <span class="hidden text-sm text-muted-foreground sm:inline">{{ taxLabels.gross }} <strong class="text-foreground">{{ money(totals.gross) }}</strong><template v-if="form.currency === 'UF' && uf"> · ≈ {{ formatMoney(grossClp) || '$0' }}</template></span>
                <Button size="sm" :disabled="saving || locked || !form.title.trim()" @click="save"><Spinner v-if="saving" /><Save v-else /> Guardar</Button>
            </div>
        </div>
        <p v-if="locked" class="border-b bg-[#FFA165]/15 px-4 py-2 text-sm text-[#9A4B00]">Esta propuesta ya fue respondida y no se puede modificar. Desde su ficha puedes crear una nueva versión.</p>
        <p v-if="errorMsg" class="border-b bg-destructive/10 px-4 py-1.5 text-xs text-destructive">{{ errorMsg }}</p>

        <div class="grid min-h-0 flex-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,560px)]">
            <!-- Formulario -->
            <div class="min-h-0 overflow-y-auto p-4 md:p-6">
                <fieldset :disabled="locked" class="mx-auto flex max-w-3xl flex-col gap-5">
                    <!-- Cliente -->
                    <section class="rounded-2xl border bg-card p-5">
                        <h2 class="mb-1 flex items-center gap-2 font-semibold"><Building2 class="size-4 text-primary" /> Cliente y destinatario</h2>
                        <p class="mb-4 text-xs text-muted-foreground">Estos datos aparecen en la propuesta. Quedan fijos al emitirla, aunque después cambies la ficha del cliente.<span v-if="lead"> Cliente asociado: <strong>{{ lead.first_name }} {{ lead.last_name }}</strong>.</span></p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField label="Empresa del CRM" class="sm:col-span-2" hint="Al elegirlo se completan los datos de la empresa.">
                                <NativeSelect v-model="form.client_id" @update:model-value="onClient"><option :value="null">— Sin empresa registrada —</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></NativeSelect>
                            </FormField>
                            <FormField label="Nombre de fantasía"><Input v-model="recipient.company" placeholder="Los Robles" /></FormField>
                            <FormField label="Razón social"><Input v-model="recipient.legal_name" placeholder="Inmobiliaria Los Robles SpA" /></FormField>
                            <FormField label="RUT"><Input v-model="recipient.rut" placeholder="76.123.456-7" @blur="recipient.rut = formatRut(recipient.rut)" /></FormField>
                            <FormField label="Giro"><Input v-model="recipient.activity" /></FormField>
                            <FormField label="Dirección" class="sm:col-span-2"><Input v-model="recipient.address" placeholder="Av. Apoquindo 4500, of. 801, Las Condes" /></FormField>
                            <FormField label="Contacto"><Input v-model="recipient.contact_name" /></FormField>
                            <FormField label="Cargo"><Input v-model="recipient.contact_role" /></FormField>
                            <FormField label="Correo"><Input v-model="recipient.email" type="email" /></FormField>
                            <FormField label="Teléfono"><Input v-model="recipient.phone" /></FormField>
                        </div>
                    </section>

                    <!-- Servicios -->
                    <section class="rounded-2xl border bg-card p-5">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="flex items-center gap-2 font-semibold"><Package class="size-4 text-primary" /> Servicios e inversión</h2>
                            <div class="flex flex-wrap gap-2">
                                <Button v-if="ai.enabled" type="button" size="sm" variant="outline" class="border-primary/40 text-primary" @click="suggestOpen = true; suggestions = []"><Sparkles /> Sugerir con IA</Button>
                                <Button type="button" size="sm" variant="outline" @click="catalogOpen = true"><Package /> Del catálogo</Button>
                                <Button type="button" size="sm" @click="addCustom"><Plus /> Servicio único</Button>
                            </div>
                        </div>

                        <p v-if="!items.length" class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">Agrega servicios del catálogo con su tarifa, o crea uno único solo para esta propuesta.</p>

                        <div class="grid gap-3">
                            <article v-for="(it, idx) in items" :key="it.key" class="rounded-xl border bg-background p-4">
                                <div class="flex items-start gap-2">
                                    <div class="grid flex-1 gap-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <Input v-model="it.name" placeholder="Nombre del servicio" class="min-w-48 flex-1 font-medium" />
                                            <span v-if="!it.service_id" class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">ÚNICO</span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                            <FormField label="Cobro"><NativeSelect v-model="it.billing"><option value="one_time">Pago único</option><option value="monthly">Mensual</option></NativeSelect></FormField>
                                            <FormField label="Cantidad"><Input v-model.number="it.quantity" type="number" min="0.01" step="1" /></FormField>
                                            <FormField label="Unidad"><Input v-model="it.unit" /></FormField>
                                            <FormField :label="`Valor neto (${form.currency})`"><Input v-model.number="it.unit_price" type="number" min="0" :step="form.currency === 'UF' ? 0.5 : 1000" /></FormField>
                                            <FormField label="Dto. %"><Input v-model.number="it.discount_pct" type="number" min="0" max="100" /></FormField>
                                        </div>
                                        <FormField label="Descripción">
                                            <RichTextEditor v-model="it.description" compact :min-height="70" placeholder="Qué incluye y para qué sirve" />
                                        </FormField>
                                        <FormField label="Entregables (uno por línea)"><Textarea :model-value="delivText(it)" rows="2" @update:model-value="(v: string | number) => setDeliv(it, String(v))" /></FormField>
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <Button v-if="ai.enabled" type="button" size="sm" variant="ghost" class="text-primary" :disabled="aiBusy === `it-${it.key}`" @click="writeItem(it)"><Spinner v-if="aiBusy === `it-${it.key}`" /><Wand2 v-else /> Redactar con IA</Button>
                                            <span v-else />
                                            <span class="text-sm"><Repeat v-if="it.billing === 'monthly'" class="mr-1 inline size-3.5 text-[#6419DB]" />Total línea{{ it.billing === 'monthly' ? ' / mes' : '' }}: <strong>{{ money(Number((it.quantity * it.unit_price * (1 - (it.discount_pct || 0) / 100)).toFixed(decimals))) }}</strong></span>
                                        </div>
                                    </div>
                                    <div class="flex flex-col">
                                        <Button type="button" variant="ghost" size="icon-sm" title="Subir" :disabled="idx === 0" @click="move(idx, -1)"><ArrowUp /></Button>
                                        <Button type="button" variant="ghost" size="icon-sm" title="Bajar" :disabled="idx === items.length - 1" @click="move(idx, 1)"><ArrowDown /></Button>
                                        <Button type="button" variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Quitar" @click="removeItem(it.key)"><Trash2 /></Button>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </section>

                    <!-- Condiciones -->
                    <section class="rounded-2xl border bg-card p-5">
                        <h2 class="mb-4 flex items-center gap-2 font-semibold"><Receipt class="size-4 text-primary" /> Condiciones</h2>
                        <div class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <FormField label="Moneda" :hint="form.currency === 'UF' && uf ? `UF hoy ${formatUfValue(uf.value)}` : undefined"><NativeSelect v-model="form.currency"><option value="UF">UF (recomendado)</option><option value="CLP">Pesos (CLP)</option></NativeSelect></FormField>
                            <FormField label="Válida hasta"><Input v-model="form.valid_until" type="date" /></FormField>
                            <FormField label="Duración (meses)" hint="Para servicios mensuales"><Input v-model.number="form.contract_months" type="number" min="1" max="60" placeholder="12" /></FormField>
                            <FormField label="Descuento">
                                <div class="flex gap-2"><NativeSelect v-model="form.discount_type" class="w-24"><option value="percent">%</option><option value="amount">{{ form.currency === 'UF' ? 'UF' : '$' }}</option></NativeSelect><Input v-model.number="form.discount_value" type="number" min="0" /></div>
                            </FormField>
                            <FormField label="Documento tributario" hint="Por defecto, el configurado en Datos de mi empresa; cámbialo solo para esta propuesta si hace falta." class="sm:col-span-2 lg:col-span-5">
                                <div class="flex flex-wrap gap-2">
                                    <NativeSelect v-model="form.tax_mode" class="min-w-64 flex-1" @change="onTaxMode"><option v-for="(label, k) in taxModes" :key="k" :value="k">{{ label }}</option></NativeSelect>
                                    <div v-if="form.tax_mode === 'iva' || form.tax_mode === 'honorarios'" class="flex items-center gap-1.5"><Input v-model.number="form.tax_rate" type="number" min="0" max="30" step="0.01" class="w-24" /><span class="text-sm text-muted-foreground">%</span></div>
                                </div>
                            </FormField>
                        </div>
                        <dl class="mt-5 grid gap-1.5 rounded-xl bg-muted/40 p-4 text-sm">
                            <div v-if="totals.one" class="flex justify-between"><dt class="text-muted-foreground">Pago único</dt><dd>{{ money(totals.one) }}</dd></div>
                            <div v-if="totals.hasMonthly" class="flex justify-between"><dt class="text-muted-foreground">Mensual × {{ totals.months }} {{ totals.months === 1 ? 'mes' : 'meses' }}</dt><dd>{{ money(totals.monthly) }} /mes</dd></div>
                            <div v-if="totals.discount" class="flex justify-between"><dt class="text-muted-foreground">Descuento aplicado</dt><dd>− {{ money(totals.discount) }}</dd></div>
                            <template v-if="taxLabels.tax">
                                <div class="flex justify-between border-t pt-1.5"><dt>{{ taxLabels.net }}</dt><dd class="font-semibold">{{ money(totals.net) }}</dd></div>
                                <div class="flex justify-between"><dt class="text-muted-foreground">{{ taxLabels.tax }}</dt><dd>{{ money(totals.tax) }}</dd></div>
                            </template>
                            <div class="flex justify-between text-base font-bold text-primary" :class="{ 'border-t pt-1.5': !taxLabels.tax }"><dt>{{ taxLabels.gross }}</dt><dd>{{ money(totals.gross) }}</dd></div>
                            <p v-if="form.tax_mode === 'honorarios'" class="text-xs text-muted-foreground">Los honorarios se hablan netos: la boleta se emite por el total y la retención la paga el cliente (si es empresa) o tú con ese mismo monto.</p>
                            <div v-if="form.currency === 'UF' && uf" class="mt-1 rounded-lg bg-background/70 p-2.5 text-xs text-muted-foreground">Equivalente ≈ <strong class="text-foreground">{{ formatMoney(grossClp) || '$0' }}</strong> · 1 UF = {{ formatUfValue(uf.value) }} ({{ uf.date }}). Al enviar la propuesta, la UF del día queda guardada como valor de referencia.</div>
                        </dl>
                    </section>

                    <!-- Contenido -->
                    <section class="rounded-2xl border bg-card p-5">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="flex items-center gap-2 font-semibold"><FileText class="size-4 text-primary" /> Contenido de la propuesta</h2>
                            <div class="flex gap-2">
                                <Button v-if="ai.enabled" type="button" size="sm" variant="outline" class="border-primary/40 text-primary" @click="draftOpen = true"><Sparkles /> Redactar con IA</Button>
                                <Button type="button" size="sm" variant="outline" @click="addSection"><Plus /> Sección</Button>
                            </div>
                        </div>
                        <p v-if="!ai.enabled && ai.can_configure" class="mb-4 rounded-xl border border-dashed p-3 text-xs text-muted-foreground"><Sparkles class="mr-1 inline size-3.5 text-primary" />¿Quieres ayuda de IA para redactar? <Link :href="aiIndex()" class="font-medium text-primary hover:underline">Configura un proveedor</Link>.</p>
                        <p class="mb-3 text-xs text-muted-foreground">Escribe con el editor (negrita, listas, enlaces) y usa los marcadores <code>[CLIENTE]</code> y <code>[CONTACTO]</code> si quieres que se reemplacen solos. La sección «Alcance de los servicios» muestra la tabla de servicios e inversión justo debajo.</p>
                        <div class="grid gap-3">
                            <article v-for="(s, idx) in sections" :key="idx" class="rounded-xl border bg-background p-4">
                                <div class="mb-2 flex items-center gap-2">
                                    <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-primary/10 text-xs font-bold text-primary">{{ idx + 1 }}</span>
                                    <Input v-model="s.title" class="font-medium" />
                                    <DropdownMenu v-if="ai.enabled">
                                        <DropdownMenuTrigger as-child><Button type="button" variant="outline" size="sm" class="shrink-0 border-primary/40 text-primary" :disabled="aiBusy === `imp-${idx}`"><Spinner v-if="aiBusy === `imp-${idx}`" /><Wand2 v-else /> IA</Button></DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-56">
                                            <DropdownMenuLabel>Mejorar este texto</DropdownMenuLabel>
                                            <DropdownMenuItem @select="improve(idx, 'write')">{{ s.body.trim() ? 'Reescribir mejor' : 'Redactar esta sección' }}</DropdownMenuItem>
                                            <DropdownMenuItem :disabled="!s.body.trim()" @select="improve(idx, 'shorter')">Hacer más breve</DropdownMenuItem>
                                            <DropdownMenuItem :disabled="!s.body.trim()" @select="improve(idx, 'persuasive')">Más persuasivo</DropdownMenuItem>
                                            <DropdownMenuItem :disabled="!s.body.trim()" @select="improve(idx, 'formal')">Más formal</DropdownMenuItem>
                                            <DropdownMenuItem :disabled="!s.body.trim()" @select="improve(idx, 'fix')">Corregir ortografía</DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem @select="customPrompt = { idx, text: '' }">Con mis instrucciones…</DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                    <Button type="button" variant="ghost" size="icon-sm" :disabled="idx === 0" @click="moveSection(idx, -1)"><ArrowUp /></Button>
                                    <Button type="button" variant="ghost" size="icon-sm" :disabled="idx === sections.length - 1" @click="moveSection(idx, 1)"><ArrowDown /></Button>
                                    <Button type="button" variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" @click="removeSection(idx)"><Trash2 /></Button>
                                </div>
                                <RichTextEditor v-model="s.body" :min-height="140" placeholder="Escribe el contenido de esta sección…" />
                            </article>
                        </div>
                    </section>

                    <section class="rounded-2xl border bg-card p-5">
                        <h2 class="mb-3 flex items-center gap-2 font-semibold"><StickyNote class="size-4 text-primary" /> Notas internas</h2>
                        <RichTextEditor v-model="form.internal_notes" compact :min-height="80" placeholder="Solo las ve el equipo (no aparecen en la propuesta)." />
                    </section>
                </fieldset>
            </div>

            <!-- Vista previa -->
            <aside class="hidden min-h-0 flex-col border-l bg-muted/30 xl:flex">
                <div class="flex items-center gap-2 border-b bg-card px-3 py-2 text-xs text-muted-foreground"><Eye class="size-4" /> Así la verá el cliente (se actualiza sola)</div>
                <iframe :srcdoc="html" title="Vista previa" class="min-h-0 flex-1 bg-[#F4F4F4]" />
            </aside>
        </div>
    </div>

    <!-- Catálogo -->
    <Dialog v-model:open="catalogOpen">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader><DialogTitle>Catálogo de servicios</DialogTitle><DialogDescription>Haz clic para agregar. Puedes ajustar cantidad y valor en la propuesta sin cambiar el catálogo.</DialogDescription></DialogHeader>
            <div class="relative"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="catalogQ" placeholder="Buscar…" class="pl-9" /></div>
            <div class="scroll-cues grid max-h-[50dvh] content-start gap-4 pr-1">
                <div v-for="[cat, list] in catalogGroups" :key="cat">
                    <p class="mb-1.5 text-xs font-semibold text-muted-foreground">{{ cat }}</p>
                    <div class="grid gap-2">
                        <button v-for="s in list" :key="s.id" type="button" class="flex items-start justify-between gap-3 rounded-xl border p-3 text-left transition hover:border-primary/50 hover:bg-primary/5" @click="addService(s)">
                            <span class="min-w-0"><span class="block text-sm font-semibold">{{ s.name }}</span><span class="line-clamp-2 text-xs text-muted-foreground">{{ stripHtml(s.description ?? '') }}</span></span>
                            <span class="shrink-0 text-right text-sm font-bold text-primary">{{ formatAmount(s.price, s.currency) }}<span class="block text-[10px] font-normal text-muted-foreground">{{ s.billing === 'monthly' ? '/ mes' : 'pago único' }}</span></span>
                        </button>
                    </div>
                </div>
                <p v-if="!catalogGroups.length" class="py-8 text-center text-sm text-muted-foreground">Sin resultados. <Link v-if="can.services" :href="servicesIndex()" class="text-primary hover:underline">Administrar catálogo</Link></p>
            </div>
        </DialogContent>
    </Dialog>

    <!-- IA: redactar propuesta -->
    <Dialog v-model:open="draftOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader><DialogTitle class="flex items-center gap-2"><Sparkles class="size-5 text-primary" /> Redactar la propuesta con IA</DialogTitle><DialogDescription>La IA escribe el resumen, objetivos, plan y condiciones según el cliente y los servicios elegidos. Reemplazará las secciones actuales.</DialogDescription></DialogHeader>
            <div class="grid gap-4">
                <FormField label="Contexto adicional (opcional)" hint="Ej: proyecto de 120 departamentos en Ñuñoa, preventa, meta de 30 reservas en 3 meses."><Textarea v-model="draft.brief" rows="4" /></FormField>
                <FormField label="Tono"><NativeSelect v-model="draft.tone"><option>Cercano y profesional</option><option>Formal</option><option>Directo y comercial</option><option>Inspirador</option></NativeSelect></FormField>
                <p class="text-[11px] text-muted-foreground">No se envía el nombre del cliente a la IA: usa marcadores que se reemplazan aquí. No inventa cifras ni plazos; revisa el resultado.</p>
            </div>
            <DialogFooter><Button variant="ghost" @click="draftOpen = false">Cancelar</Button><Button :disabled="aiBusy === 'draft'" @click="runDraft"><Spinner v-if="aiBusy === 'draft'" /><Sparkles v-else /> {{ aiBusy === 'draft' ? 'Redactando…' : 'Redactar' }}</Button></DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- IA: sugerir servicios -->
    <Dialog v-model:open="suggestOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader><DialogTitle class="flex items-center gap-2"><Sparkles class="size-5 text-primary" /> Servicios sugeridos</DialogTitle><DialogDescription>La IA elige del catálogo lo que mejor calza con lo que necesita el cliente.</DialogDescription></DialogHeader>
            <FormField label="¿Qué necesita el cliente?" hint="Si hay un cliente asociado, también se usa su mensaje y etapa."><Textarea v-model="suggestBrief" rows="3" placeholder="Quiere captar compradores para un proyecto nuevo y ordenar el seguimiento de sus clientes." /></FormField>
            <Button :disabled="aiBusy === 'suggest'" class="w-fit" @click="runSuggest"><Spinner v-if="aiBusy === 'suggest'" /><Sparkles v-else /> Sugerir</Button>
            <ul v-if="suggestions.length" class="grid gap-2">
                <li v-for="s in suggestions" :key="s.service_id" class="flex items-start justify-between gap-3 rounded-xl border p-3">
                    <span class="min-w-0 text-sm"><strong>{{ serviceById(s.service_id)?.name }}</strong><span class="block text-xs text-muted-foreground">{{ s.reason }}</span></span>
                    <Button v-if="serviceById(s.service_id)" size="sm" variant="outline" :disabled="items.some((i) => i.service_id === s.service_id)" @click="addService(serviceById(s.service_id)!)">{{ items.some((i) => i.service_id === s.service_id) ? 'Agregado' : 'Agregar' }}</Button>
                </li>
            </ul>
        </DialogContent>
    </Dialog>

    <!-- IA: instrucciones propias -->
    <Dialog :open="!!customPrompt" @update:open="(v: boolean) => !v && (customPrompt = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Instrucciones para la IA</DialogTitle><DialogDescription>Dile qué cambiar en este texto.</DialogDescription></DialogHeader>
            <Textarea v-if="customPrompt" v-model="customPrompt.text" rows="3" placeholder="Ej: destaca el ahorro de tiempo y agrega un plazo de entrega de 2 semanas." />
            <DialogFooter><Button variant="ghost" @click="customPrompt = null">Cancelar</Button><Button :disabled="!customPrompt?.text.trim()" @click="customPrompt && (improve(customPrompt.idx, 'custom', customPrompt.text), (customPrompt = null))">Aplicar</Button></DialogFooter>
        </DialogContent>
    </Dialog>
</template>
