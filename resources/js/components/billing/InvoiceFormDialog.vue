<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Sparkles, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/composables/usePermissions';
import { money } from '@/lib/billingUi';
import { HttpError } from '@/lib/http';
import type { InvoiceRow } from '@/types/billing';

const props = defineProps<{
    invoice: InvoiceRow | null;
    defaults?: { client_id?: number | null; client_service_id?: number | null } | null;
    clients: { id: number; name: string; kind?: 'person' | 'company' }[];
    services: { id: number; name: string; client_id: number; currency: string; price: number }[];
    taxRate: number;
    aiAvailable?: boolean;
}>();
const { can } = usePermissions();
const page = usePage();
const aiOn = computed(() => props.aiAvailable ?? Boolean(page.props.ai_available));
const open = defineModel<boolean>('open', { default: false });
const retentionRate = computed(() => Number(page.props.retention_rate ?? 0));
const billingDefaults = page.props.billing_defaults as { document_type: string; tax_rate: number } | null | undefined;

const blank = () => ({
    client_id: (props.defaults?.client_id ?? '') as number | string,
    client_service_id: (props.defaults?.client_service_id ?? '') as number | string,
    number: '', concept: '', period_start: '', period_end: '', issue_date: '', due_date: new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10),
    currency: 'CLP', amount_net: '', tax_rate: String(billingDefaults?.tax_rate ?? props.taxRate), document_type: billingDefaults?.document_type ?? 'boleta', kind: 'honorarios', retention_rate: String(retentionRate.value), retention_by_client: false, auto_remind: false, payment_link: '', notes: '', pdf: null as File | null, issue: false,
    paid: false, paid_at: new Date().toISOString().slice(0, 10), payment_method: 'Transferencia', payment_reference: '',
});
const form = useForm(blank());
const fileInput = ref<HTMLInputElement | null>(null);

watch(open, (o) => {
    if (!o) return;
    form.reset();
    form.clearErrors();
    const i = props.invoice;
    Object.assign(form, i
        ? {
              client_id: i.client_id, client_service_id: i.client_service_id ?? '', number: i.number ?? '', concept: i.concept, period_start: i.period_start ?? '', period_end: i.period_end ?? '',
              issue_date: i.issue_date ?? '', due_date: i.due_date, currency: i.currency, amount_net: String(i.amount_net), tax_rate: String(i.tax_rate), document_type: i.document_type, kind: i.kind, retention_rate: String(i.retention_rate), retention_by_client: i.retention_by_client, auto_remind: i.auto_remind,
              payment_link: i.payment_link ?? '', notes: i.notes ?? '', pdf: null, issue: false,
              paid: i.status === 'paid', paid_at: i.paid_at ? i.paid_at.slice(0, 10) : new Date().toISOString().slice(0, 10), payment_method: i.payment_method ?? 'Transferencia', payment_reference: i.payment_reference ?? '',
          }
        : blank());
    if (fileInput.value) fileInput.value.value = '';
    aiNote.value = null;
    aiWarnings.value = [];
});

const isPaidInvoice = computed(() => props.invoice?.status === 'paid');
const canPay = computed(() => can('billing.mark_paid'));

const clientServices = computed(() => props.services.filter((s) => s.client_id === Number(form.client_id)));

const fromService = () => {
    const s = props.services.find((x) => x.id === Number(form.client_service_id));
    if (!s) return;
    form.currency = s.currency;
    form.amount_net = String(s.price);
    form.concept = form.concept || s.name;
};

const round = (v: number) => (form.currency === 'UF' ? Math.round(v * 100) / 100 : Math.round(v));
const isBoleta = computed(() => form.document_type === 'boleta');
const gross = computed(() => (form.amount_net === '' || !Number.isFinite(Number(form.amount_net)) ? null : Number(form.amount_net)));
const retention = computed(() => (gross.value === null || !isBoleta.value ? 0 : round((gross.value * (Number(form.retention_rate) || 0)) / 100)));
const total = computed(() => {
    if (gross.value === null) return null;
    if (isBoleta.value) return round(form.retention_by_client ? gross.value - retention.value : gross.value);
    return round(gross.value * (1 + (Number(form.tax_rate) || 0) / 100));
});

// Si el cliente es una empresa, normalmente retiene; una persona paga el bruto.
watch(() => form.client_id, (id) => {
    if (props.invoice) return;
    form.retention_by_client = props.clients.find((c) => c.id === Number(id))?.kind === 'company';
});

// ---- lectura del PDF con IA ----
const reading = ref(false);
const aiNote = ref<string | null>(null);
const aiWarnings = ref<string[]>([]);

type Extracted = { fields: Record<string, string | number | boolean>; client: { id: number; name: string } | null; warnings: string[] };
const readWithAi = async () => {
    if (!form.pdf) return;
    reading.value = true;
    aiNote.value = null;
    aiWarnings.value = [];
    try {
        const body = new FormData();
        body.append('pdf', form.pdf);
        const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1];
        const res = await fetch('/billing/invoices/extract', { method: 'POST', credentials: 'same-origin', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) } });
        if (!res.ok) throw new HttpError(res.status, await res.json().catch(() => null));
        const d = (await res.json()) as Extracted;
        const f = d.fields;
        if (!props.invoice && d.client) form.client_id = d.client.id;
        if (f.number) form.number = String(f.number);
        if (f.concept && !form.concept) form.concept = String(f.concept);
        if (f.currency) form.currency = String(f.currency);
        if (f.amount_net !== undefined) form.amount_net = String(f.amount_net);
        if (f.tax_rate !== undefined) form.tax_rate = String(f.tax_rate);
        if (f.document_type) form.document_type = String(f.document_type);
        if (f.retention_rate !== undefined) form.retention_rate = String(f.retention_rate);
        if (f.issue_date) form.issue_date = String(f.issue_date);
        if (f.due_date) form.due_date = String(f.due_date);
        else if (f.issue_date) form.due_date = String(f.issue_date);
        if (f.period_start) form.period_start = String(f.period_start);
        if (f.period_end) form.period_end = String(f.period_end);
        if (f.paid_indicated && canPay.value && !props.invoice) { form.paid = true; form.paid_at = String(f.issue_date ?? form.paid_at); }
        aiWarnings.value = d.warnings;
        aiNote.value = d.client ? `Leí el documento ${f.number ?? ''} de ${d.client.name}. Revisa los datos antes de guardar.` : `Leí el documento ${f.number ?? ''}. Revisa los datos antes de guardar.`;
    } catch (e) {
        aiWarnings.value = [e instanceof HttpError ? (e.body?.message ?? 'No pude leer el PDF.') : 'No pude leer el PDF.'];
    } finally {
        reading.value = false;
    }
};

const onFile = (e: Event) => {
    form.pdf = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (form.pdf && aiOn.value && !props.invoice) void readWithAi();
};

const submit = () => {
    const url = props.invoice ? `/billing/invoices/${props.invoice.id}` : '/billing/invoices';
    form.transform((d) => ({ ...d, ...(props.invoice ? { _method: 'put' } : {}), issue: d.pdf ? d.issue : false, ...(d.paid ? { auto_remind: false } : { paid: false, paid_at: '', payment_method: '', payment_reference: '' }) }))
        .post(url, { forceFormData: true, preserveScroll: true, onSuccess: () => (open.value = false) });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ invoice ? 'Editar cobro' : 'Nuevo cobro' }}</DialogTitle>
                <DialogDescription>La boleta o factura la emites en el SII: aquí registras el cobro y adjuntas su PDF.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField label="Cliente" for="inv-client" required :error="form.errors.client_id">
                    <NativeSelect id="inv-client" v-model="form.client_id" :disabled="!!invoice"><option value="">Selecciona…</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></NativeSelect>
                </FormField>
                <FormField label="Servicio" for="inv-service" hint="Opcional." :error="form.errors.client_service_id">
                    <NativeSelect id="inv-service" v-model="form.client_service_id" :disabled="!form.client_id" @update:model-value="fromService"><option value="">Sin servicio</option><option v-for="s in clientServices" :key="s.id" :value="s.id">{{ s.name }}</option></NativeSelect>
                </FormField>
                <FormField label="Concepto" for="inv-concept" required class="sm:col-span-2" :error="form.errors.concept"><Input id="inv-concept" v-model="form.concept" placeholder="Regularización · 50% de anticipo" /></FormField>

                <FormField label="Documento" for="inv-doc" :error="form.errors.document_type"><NativeSelect id="inv-doc" v-model="form.document_type"><option value="boleta">Boleta de honorarios</option><option value="factura">Factura</option><option value="otro">Otro documento</option></NativeSelect></FormField>
                <FormField label="Tipo de cobro" for="inv-kind" :error="form.errors.kind"><NativeSelect id="inv-kind" v-model="form.kind"><option value="honorarios">Honorarios</option><option value="obra">Obra</option></NativeSelect></FormField>
                <FormField label="Moneda" for="inv-cur"><NativeSelect id="inv-cur" v-model="form.currency"><option value="CLP">Pesos (CLP)</option><option value="UF">UF</option></NativeSelect></FormField>
                <FormField :label="isBoleta ? 'Honorarios brutos' : 'Monto neto'" for="inv-net" required :error="form.errors.amount_net"><Input id="inv-net" v-model="form.amount_net" type="number" min="0" :step="form.currency === 'UF' ? 0.01 : 1" /></FormField>
                <template v-if="isBoleta">
                    <FormField label="Retención %" for="inv-ret" :error="form.errors.retention_rate" hint="Tasa vigente del año (editable)."><Input id="inv-ret" v-model="form.retention_rate" type="number" min="0" max="100" step="0.01" /></FormField>
                    <label class="flex items-start gap-3 rounded-xl border p-3 text-sm">
                        <Switch :model-value="form.retention_by_client" @update:model-value="(v: boolean) => (form.retention_by_client = v)" />
                        <span><strong>El cliente retiene</strong><span class="block text-xs text-muted-foreground">Empresas: pagan el líquido y entregan la retención al SII. Personas: pagan el bruto.</span></span>
                    </label>
                </template>
                <FormField v-else label="IVA %" for="inv-tax" :error="form.errors.tax_rate"><Input id="inv-tax" v-model="form.tax_rate" type="number" min="0" max="100" step="0.01" /></FormField>
                <div class="flex flex-col justify-end gap-0.5 pb-1 text-sm">
                    <span v-if="isBoleta && gross !== null" class="text-xs text-muted-foreground">Retención {{ form.retention_rate || 0 }}%: {{ money(retention, form.currency) }}<template v-if="!form.retention_by_client"> (a cargo de quien emite)</template></span>
                    <span><span class="text-muted-foreground">{{ isBoleta && form.retention_by_client ? 'Líquido a cobrar' : 'Total a cobrar' }}:&nbsp;</span><strong class="text-base tabular-nums">{{ total === null ? '—' : money(total, form.currency) }}</strong></span>
                </div>

                <FormField label="N° de documento" for="inv-number" hint="Folio de la boleta o factura emitida." :error="form.errors.number"><Input id="inv-number" v-model="form.number" placeholder="F-1024" /></FormField>
                <FormField label="Vencimiento" for="inv-due" required :error="form.errors.due_date"><Input id="inv-due" v-model="form.due_date" type="date" /></FormField>
                <FormField label="Emisión" for="inv-issue" :error="form.errors.issue_date"><Input id="inv-issue" v-model="form.issue_date" type="date" /></FormField>
                <FormField label="Período desde" for="inv-ps" :error="form.errors.period_start"><Input id="inv-ps" v-model="form.period_start" type="date" /></FormField>
                <FormField label="Período hasta" for="inv-pe" :error="form.errors.period_end"><Input id="inv-pe" v-model="form.period_end" type="date" /></FormField>
                <FormField label="Enlace de pago" for="inv-link" class="sm:col-span-2" hint="Opcional (Flow, Webpay, transferencia…)." :error="form.errors.payment_link"><Input id="inv-link" v-model="form.payment_link" type="url" placeholder="https://" /></FormField>

                <FormField label="PDF de la boleta o factura" for="inv-pdf" class="sm:col-span-2" :hint="invoice?.has_pdf ? `Ya hay un PDF adjunto (${invoice.pdf_name}). Elige otro para reemplazarlo.` : 'Máx. 10 MB.'" :error="form.errors.pdf">
                    <input id="inv-pdf" ref="fileInput" type="file" accept="application/pdf" class="block w-full text-sm file:mr-3 file:rounded-full file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary" @change="onFile" />
                </FormField>
                <p v-if="form.pdf && !aiOn" class="text-xs text-muted-foreground sm:col-span-2">Para que la IA lea el documento y complete los datos, configura un proveedor en <strong>Proveedores de IA</strong> (y que tu rol tenga el permiso «Usar IA»).</p>
                <div v-if="form.pdf && aiOn" class="flex flex-wrap items-center gap-2 sm:col-span-2">
                    <Button type="button" size="sm" variant="outline" :disabled="reading" @click="readWithAi"><Spinner v-if="reading" /><Sparkles v-else /> {{ reading ? 'Leyendo el documento…' : 'Leer datos del PDF con IA' }}</Button>
                    <span v-if="aiNote" class="text-xs text-brand-green">{{ aiNote }}</span>
                </div>
                <ul v-if="aiWarnings.length" class="grid gap-1 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-800 sm:col-span-2 dark:text-amber-300">
                    <li v-for="w in aiWarnings" :key="w" class="flex gap-2"><TriangleAlert class="mt-0.5 size-3.5 shrink-0" /> {{ w }}</li>
                </ul>

                <div v-if="canPay" class="grid gap-3 rounded-xl border p-3 text-sm sm:col-span-2">
                    <label class="flex items-start gap-3">
                        <Switch :model-value="form.paid" :disabled="isPaidInvoice" @update:model-value="(v: boolean) => (form.paid = v)" />
                        <span><strong>{{ isPaidInvoice ? 'Cobro pagado' : 'Ya está pagado (documento antiguo)' }}</strong><span v-if="!isPaidInvoice" class="block text-xs text-muted-foreground">Para llevar el historial: queda como pagada, sin recordatorios ni avisos al cliente.</span></span>
                    </label>
                    <div v-if="form.paid" class="grid gap-3 sm:grid-cols-3">
                        <FormField label="Fecha de pago" for="inv-paid-at" :error="form.errors.paid_at"><Input id="inv-paid-at" v-model="form.paid_at" type="date" /></FormField>
                        <FormField label="Medio de pago" for="inv-paid-m" :error="form.errors.payment_method"><NativeSelect id="inv-paid-m" v-model="form.payment_method"><option>Transferencia</option><option>Webpay</option><option>Flow</option><option>MercadoPago</option><option>Tarjeta</option><option>Efectivo / cheque</option><option>Otro</option></NativeSelect></FormField>
                        <FormField label="Referencia" for="inv-paid-r" :error="form.errors.payment_reference"><Input id="inv-paid-r" v-model="form.payment_reference" placeholder="N° de operación" /></FormField>
                    </div>
                </div>

                <label v-if="form.pdf && !form.paid && (!invoice || invoice.status === 'scheduled')" class="flex items-start gap-3 rounded-xl border p-3 text-sm sm:col-span-2">
                    <Switch :model-value="form.issue" @update:model-value="(v: boolean) => (form.issue = v)" />
                    <span><strong>Emitir ahora</strong><span class="block text-xs text-muted-foreground">Queda «por pagar» y el cliente la ve en su portal.</span></span>
                </label>
                <label v-if="!form.paid" class="flex items-start gap-3 rounded-xl border p-3 text-sm sm:col-span-2">
                    <Switch :model-value="form.auto_remind" @update:model-value="(v: boolean) => (form.auto_remind = v)" />
                    <span><strong>Recordatorios automáticos</strong><span class="block text-xs text-muted-foreground">Envía correos antes y después del vencimiento mientras esté por pagar. Si es un cobro único, déjalo apagado y usa «Enviar cobro».</span></span>
                </label>
                <FormField label="Notas internas" for="inv-notes" class="sm:col-span-2" :error="form.errors.notes"><Textarea id="inv-notes" v-model="form.notes" rows="2" /></FormField>

                <DialogFooter class="gap-2 sm:col-span-2">
                    <Button type="button" variant="outline" @click="open = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> {{ invoice ? 'Guardar' : 'Registrar cobro' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
