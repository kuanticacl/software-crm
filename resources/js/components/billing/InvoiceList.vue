<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Ban, CheckCircle2, FileDown, MoreHorizontal, Paperclip, Pencil, RotateCcw, Send, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/components/FormField.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InvoiceFormDialog from '@/components/billing/InvoiceFormDialog.vue';
import StatusPill from '@/components/billing/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import { fmtDate, money } from '@/lib/billingUi';
import type { InvoiceRow } from '@/types/billing';

const props = defineProps<{
    invoices: InvoiceRow[];
    showClient?: boolean;
    clients: { id: number; name: string }[];
    services: { id: number; name: string; client_id: number; currency: string; price: number }[];
    taxRate: number;
    emptyText?: string;
    aiAvailable?: boolean;
}>();

const { can } = usePermissions();

// ---- editar ----
const editing = ref<InvoiceRow | null>(null);
const editOpen = ref(false);
const edit = (i: InvoiceRow) => { editing.value = i; editOpen.value = true; };

// ---- adjuntar PDF / emitir ----
const pdfFor = ref<InvoiceRow | null>(null);
const pdfForm = useForm({ pdf: null as File | null, number: '', issue: true });
const openPdf = (i: InvoiceRow) => { pdfFor.value = i; pdfForm.reset(); pdfForm.clearErrors(); pdfForm.number = i.number ?? ''; pdfForm.issue = i.status === 'scheduled'; };
const submitPdf = () => {
    if (!pdfFor.value) return;
    pdfForm.post(`/billing/invoices/${pdfFor.value.id}/pdf`, { forceFormData: true, preserveScroll: true, onSuccess: () => (pdfFor.value = null) });
};

// ---- pagar ----
const payFor = ref<InvoiceRow | null>(null);
const payForm = useForm({ payment_method: 'Transferencia', payment_reference: '', paid_at: new Date().toISOString().slice(0, 10) });
const openPay = (i: InvoiceRow) => { payFor.value = i; payForm.reset(); };
const submitPay = () => {
    if (!payFor.value) return;
    payForm.post(`/billing/invoices/${payFor.value.id}/pay`, { preserveScroll: true, onSuccess: () => (payFor.value = null) });
};

// ---- acciones simples ----
const toDelete = ref<InvoiceRow | null>(null);
const toCancel = ref<InvoiceRow | null>(null);
const sending = ref<number | null>(null);
const post = (url: string, id?: number) => {
    if (id) sending.value = id;
    router.post(url, {}, { preserveScroll: true, onFinish: () => (sending.value = null) });
};
const subtitle = (i: InvoiceRow) => [i.service?.name, i.period_start ? `${fmtDate(i.period_start)}${i.period_end ? ` – ${fmtDate(i.period_end)}` : ''}` : null, i.sent_at ? `enviada ${fmtDate(i.sent_at)}` : null].filter(Boolean).join(' · ');
const sendLabel = (i: InvoiceRow) => (i.sent_at ? 'Enviar recordatorio' : 'Enviar cobro');
const removeInvoice = computed(() => toDelete.value);
</script>

<template>
    <Table>
        <TableHeader>
            <TableRow class="hover:bg-transparent">
                <TableHead>Cobro</TableHead>
                <TableHead v-if="showClient" class="hidden md:table-cell">Cliente</TableHead>
                <TableHead>Vence</TableHead>
                <TableHead class="text-right">Total</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead class="w-12" />
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableEmpty v-if="!invoices.length" :colspan="showClient ? 6 : 5">{{ emptyText ?? 'No hay cobros para mostrar.' }}</TableEmpty>
            <TableRow v-for="i in invoices" :key="i.id">
                <TableCell>
                    <p class="font-medium">{{ i.number ? `${i.number} · ` : '' }}{{ i.concept }}</p>
                    <p class="text-xs text-muted-foreground">{{ subtitle(i) }}</p>
                    <p v-if="showClient" class="text-xs text-muted-foreground md:hidden">{{ i.client?.name }}</p>
                </TableCell>
                <TableCell v-if="showClient" class="hidden md:table-cell">{{ i.client?.name }}</TableCell>
                <TableCell class="whitespace-nowrap">{{ fmtDate(i.due_date) }}<p v-if="i.paid_at" class="text-xs text-brand-green">Pagada {{ fmtDate(i.paid_at) }}</p></TableCell>
                <TableCell class="text-right whitespace-nowrap tabular-nums">
                    {{ money(i.amount_total, i.currency) }}
                    <p v-if="i.currency === 'UF' && i.total_clp" class="text-xs text-muted-foreground">≈ {{ money(i.total_clp) }}</p>
                </TableCell>
                <TableCell>
                    <StatusPill kind="invoice" :status="i.display_status" />
                    <p v-if="i.status === 'scheduled' && !i.has_pdf" class="mt-0.5 text-[11px] text-amber-600 dark:text-amber-400">Falta el PDF</p>
                </TableCell>
                <TableCell>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child><Button variant="ghost" size="icon-sm" title="Acciones"><Spinner v-if="sending === i.id" /><MoreHorizontal v-else /></Button></DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-60">
                            <DropdownMenuItem v-if="i.has_pdf" as-child><a :href="`/billing/invoices/${i.id}/download`"><FileDown /> Descargar PDF</a></DropdownMenuItem>
                            <template v-if="can('billing.manage')">
                                <DropdownMenuItem v-if="['scheduled', 'issued'].includes(i.status)" @select="openPdf(i)"><Paperclip /> {{ i.has_pdf ? 'Reemplazar PDF' : i.status === 'scheduled' ? 'Adjuntar PDF y emitir' : 'Adjuntar PDF' }}</DropdownMenuItem>
                                <DropdownMenuItem v-if="i.status === 'issued'" @select="post(`/billing/invoices/${i.id}/send`, i.id)"><Send /> {{ sendLabel(i) }}</DropdownMenuItem>
                                <DropdownMenuItem v-if="i.status !== 'cancelled'" @select="edit(i)"><Pencil /> Editar</DropdownMenuItem>
                            </template>
                            <DropdownMenuItem v-if="can('billing.mark_paid') && ['issued', 'scheduled'].includes(i.status)" @select="openPay(i)"><CheckCircle2 /> Marcar como pagada</DropdownMenuItem>
                            <DropdownMenuItem v-if="can('billing.mark_paid') && ['paid', 'cancelled'].includes(i.status)" @select="post(`/billing/invoices/${i.id}/reopen`)"><RotateCcw /> Reabrir</DropdownMenuItem>
                            <template v-if="can('billing.delete')">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem v-if="!['paid', 'cancelled'].includes(i.status)" @select="toCancel = i"><Ban /> Anular cobro</DropdownMenuItem>
                                <DropdownMenuItem v-if="i.status !== 'paid'" class="text-destructive" @select="toDelete = i"><Trash2 /> Eliminar</DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </TableCell>
            </TableRow>
        </TableBody>
    </Table>

    <InvoiceFormDialog v-model:open="editOpen" :invoice="editing" :clients="clients" :services="services" :tax-rate="taxRate" :ai-available="aiAvailable" />

    <Dialog :open="!!pdfFor" @update:open="(v: boolean) => !v && (pdfFor = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Adjuntar boleta o factura (PDF)</DialogTitle><DialogDescription>{{ pdfFor?.concept }}</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitPdf">
                <FormField label="Archivo PDF" for="pdf-file" required :error="pdfForm.errors.pdf"><input id="pdf-file" type="file" accept="application/pdf" required class="block w-full text-sm file:mr-3 file:rounded-full file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary" @change="(e: Event) => (pdfForm.pdf = (e.target as HTMLInputElement).files?.[0] ?? null)" /></FormField>
                <FormField label="N° de documento" for="pdf-number" :error="pdfForm.errors.number"><Input id="pdf-number" v-model="pdfForm.number" placeholder="F-1024" /></FormField>
                <label v-if="pdfFor?.status === 'scheduled'" class="flex items-start gap-3 rounded-xl border p-3 text-sm"><Switch :model-value="pdfForm.issue" @update:model-value="(v: boolean) => (pdfForm.issue = v)" /><span><strong>Emitir ahora</strong><span class="block text-xs text-muted-foreground">Pasa a «por pagar» y el cliente la ve en su portal.</span></span></label>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="pdfFor = null">Cancelar</Button><Button type="submit" :disabled="pdfForm.processing || !pdfForm.pdf"><Spinner v-if="pdfForm.processing" /> Guardar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="!!payFor" @update:open="(v: boolean) => !v && (payFor = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Marcar como pagada</DialogTitle><DialogDescription>{{ payFor?.number ?? '' }} {{ payFor?.concept }} · {{ payFor ? money(payFor.amount_total, payFor.currency) : '' }}</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitPay">
                <FormField label="Fecha de pago" for="pay-date" :error="payForm.errors.paid_at"><Input id="pay-date" v-model="payForm.paid_at" type="date" /></FormField>
                <FormField label="Medio de pago" for="pay-method" :error="payForm.errors.payment_method">
                    <NativeSelect id="pay-method" v-model="payForm.payment_method"><option>Transferencia</option><option>Webpay</option><option>Flow</option><option>MercadoPago</option><option>Tarjeta</option><option>Efectivo / cheque</option><option>Otro</option></NativeSelect>
                </FormField>
                <FormField label="Referencia / comprobante" for="pay-ref" hint="Opcional: N° de operación." :error="payForm.errors.payment_reference"><Input id="pay-ref" v-model="payForm.payment_reference" /></FormField>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="payFor = null">Cancelar</Button><Button type="submit" :disabled="payForm.processing"><Spinner v-if="payForm.processing" /> Confirmar pago</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toCancel" title="Anular cobro" description="El cobro queda anulado y deja de verse en el portal del cliente. Puedes reabrirlo después." confirm-label="Anular" @update:open="(v: boolean) => !v && (toCancel = null)" @confirm="toCancel && post(`/billing/invoices/${toCancel.id}/cancel`); toCancel = null" />
    <ConfirmDialog :open="!!removeInvoice" title="Eliminar cobro" :description="`Se eliminará «${removeInvoice?.concept}». Esta acción no se puede deshacer desde la interfaz.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="removeInvoice && router.delete(`/billing/invoices/${removeInvoice.id}`, { preserveScroll: true }); toDelete = null" />
</template>
