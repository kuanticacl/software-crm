<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Layers, Link2, Pencil, Plus, Receipt, RefreshCw, Trash2, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import InvoiceFormDialog from '@/components/billing/InvoiceFormDialog.vue';
import InvoiceList from '@/components/billing/InvoiceList.vue';
import StatusPill from '@/components/billing/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import { cycleLabels, cyclePer, fmtDate, money, offsetLabel } from '@/lib/billingUi';
import type { InvoiceRow, ServiceRow } from '@/types/billing';

type Expense = { id: number; concept: string; currency: string; amount: number; amount_clp: number; incurred_on: string; notes: string | null };

const props = defineProps<{
    service: ServiceRow & {
        description: string | null; internal_notes: string | null; payment_link: string | null; reminder_offsets: number[] | null; billing_day: number | null;
        proposal: { id: number; number: string; title: string } | null; client: { id: number; name: string; legal_name: string | null; tax_id: string | null } | null; children: ServiceRow[];
    };
    invoices: InvoiceRow[];
    expenses: Expense[] | null;
    financials: { billed: number; paid: number; expenses: number; margin: number } | null;
    can: { costs: boolean };
    taxRate: number;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Proyectos', href: '/contracts' }] } });
const { can } = usePermissions();
const canCosts = computed(() => props.can.costs);

const newInvoice = ref(false);
const clients = computed(() => (props.service.client ? [{ id: props.service.client.id, name: props.service.client.name }] : []));
const svc = computed(() => [{ id: props.service.id, name: props.service.name, client_id: props.service.client?.id ?? 0, currency: props.service.currency, price: props.service.price }]);

// ---- gastos (solo uso interno) ----
const expOpen = ref(false);
const expForm = useForm({ concept: '', currency: 'CLP', amount: '', incurred_on: new Date().toISOString().slice(0, 10), notes: '' });
const saveExpense = () => expForm.post(`/contracts/${props.service.id}/expenses`, { preserveScroll: true, onSuccess: () => { expOpen.value = false; expForm.reset(); } });
const delExpense = ref<Expense | null>(null);

const toDelete = ref(false);
const destroy = () => router.delete(`/contracts/${props.service.id}`, { onFinish: () => (toDelete.value = false) });
</script>

<template>
    <Head :title="service.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="service.name" :description="service.client ? `${service.client.name}${service.client.tax_id ? ' · ' + service.client.tax_id : ''}` : undefined">
            <template #actions>
                <StatusPill kind="service" :status="service.status" />
                <Button v-if="can('contracts.update')" variant="outline" as-child><Link :href="`/contracts/${service.id}/edit`"><Pencil /> Editar</Link></Button>
                <Button v-if="can('contracts.delete')" variant="outline" class="text-destructive" @click="toDelete = true"><Trash2 /> Eliminar</Button>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <DataCard class="h-fit p-5">
                <p v-if="service.description" class="mb-4 text-sm whitespace-pre-line">{{ service.description }}</p>
                <dl class="grid gap-3 text-sm">
                    <div><dt class="text-xs text-muted-foreground">Modalidad</dt><dd>{{ service.modality === 'honorarios_obra' ? 'Honorarios + obra completa' : 'Solo honorarios' }}</dd></div>
                    <div v-if="service.project_address"><dt class="text-xs text-muted-foreground">Dirección del proyecto</dt><dd>{{ service.project_address }}</dd></div>
                    <div v-if="service.works_budget"><dt class="text-xs text-muted-foreground">Presupuesto de obra (informativo)</dt><dd class="tabular-nums">{{ money(service.works_budget, service.currency) }}</dd></div>
                    <div><dt class="text-xs text-muted-foreground">Honorarios</dt><dd class="font-medium tabular-nums">{{ money(service.price, service.currency) }} <span class="text-xs font-normal text-muted-foreground">{{ cyclePer[service.billing_cycle] }}</span></dd></div>
                    <div><dt class="text-xs text-muted-foreground">Ciclo de cobro</dt><dd>{{ cycleLabels[service.billing_cycle] }}<span v-if="service.billing_day"> · día {{ service.billing_day }}</span></dd></div>
                    <div><dt class="flex items-center gap-1 text-xs text-muted-foreground"><CalendarDays class="size-3" /> Vigencia</dt><dd>{{ fmtDate(service.start_date) }} → {{ service.end_date ? fmtDate(service.end_date) : 'sin término' }}</dd></div>
                    <div><dt class="flex items-center gap-1 text-xs text-muted-foreground"><RefreshCw class="size-3" /> Renovación</dt><dd>{{ service.auto_renew ? 'Automática' : 'No se renueva sola' }}</dd></div>
                    <div v-if="service.next_charge_on"><dt class="text-xs text-muted-foreground">Próximo cobro</dt><dd>{{ fmtDate(service.next_charge_on) }}</dd></div>
                    <div v-if="service.billing_cycle !== 'one_time'"><dt class="text-xs text-muted-foreground">Recordatorios de pago</dt><dd class="text-xs">{{ service.reminder_offsets ? service.reminder_offsets.map(offsetLabel).join(' · ') : 'Los de la cobranza (por defecto)' }}</dd></div>
                    <div v-if="service.proposal"><dt class="text-xs text-muted-foreground">Propuesta</dt><dd><Link :href="`/proposals/${service.proposal.id}`" class="text-primary hover:underline">{{ service.proposal.number }} · {{ service.proposal.title }}</Link></dd></div>
                    <div v-if="service.parent"><dt class="flex items-center gap-1 text-xs text-muted-foreground"><Link2 class="size-3" /> Asociado a</dt><dd><Link :href="`/contracts/${service.parent.id}`" class="text-primary hover:underline">{{ service.parent.name }}</Link></dd></div>
                    <div v-if="service.payment_link"><dt class="text-xs text-muted-foreground">Enlace de pago</dt><dd class="truncate"><a :href="service.payment_link" target="_blank" rel="noopener" class="text-primary hover:underline">{{ service.payment_link }}</a></dd></div>
                    <div v-if="service.internal_notes"><dt class="text-xs text-muted-foreground">Notas internas</dt><dd class="whitespace-pre-line text-muted-foreground">{{ service.internal_notes }}</dd></div>
                </dl>
            </DataCard>

            <div class="grid gap-4 lg:col-span-2">
                <DataCard v-if="financials" class="grid grid-cols-2 gap-px bg-border sm:grid-cols-4">
                    <div v-for="f in [{ l: 'Facturado (neto)', v: financials.billed }, { l: 'Cobrado (neto)', v: financials.paid }, { l: 'Gastos y costos', v: financials.expenses }, { l: 'Margen', v: financials.margin }]" :key="f.l" class="bg-card p-4">
                        <p class="text-xs text-muted-foreground">{{ f.l }}</p>
                        <p :class="['text-lg font-semibold tabular-nums', f.l === 'Margen' && f.v < 0 ? 'text-destructive' : '']">{{ money(f.v) }}</p>
                    </div>
                </DataCard>

                <DataCard v-if="service.children.length">
                    <div class="flex items-center gap-2 border-b px-5 py-3 font-semibold"><Layers class="size-4" /> Servicios asociados ({{ service.children.length }})</div>
                    <ul class="divide-y">
                        <li v-for="c in service.children" :key="c.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                            <Link :href="`/contracts/${c.id}`" class="min-w-0"><p class="truncate font-medium hover:text-primary">{{ c.name }}</p><p class="text-xs text-muted-foreground">{{ cycleLabels[c.billing_cycle] }} · hasta {{ c.end_date ? fmtDate(c.end_date) : 'sin término' }}{{ c.auto_renew ? ' · renovación automática' : '' }}</p></Link>
                            <StatusPill kind="service" :status="c.status" />
                        </li>
                    </ul>
                </DataCard>

                <DataCard>
                    <div class="flex items-center justify-between gap-2 border-b px-5 py-3">
                        <span class="flex items-center gap-2 font-semibold"><Receipt class="size-4" /> Cobros y facturas</span>
                        <Button v-if="can('billing.manage')" size="sm" @click="newInvoice = true"><Plus /> Nuevo cobro</Button>
                    </div>
                    <InvoiceList :invoices="invoices" :clients="clients" :services="svc" :tax-rate="taxRate" empty-text="Aún no hay cobros para este servicio." />
                </DataCard>

                <DataCard v-if="canCosts && expenses">
                    <div class="flex items-center justify-between gap-2 border-b px-5 py-3">
                        <span class="flex items-center gap-2 font-semibold"><Wallet class="size-4" /> Costos y gastos <span class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">Solo interno</span></span>
                        <Button size="sm" variant="outline" @click="expOpen = true"><Plus /> Registrar gasto</Button>
                    </div>
                    <Table>
                        <TableHeader><TableRow class="hover:bg-transparent"><TableHead>Concepto</TableHead><TableHead>Fecha</TableHead><TableHead class="text-right">Monto</TableHead><TableHead class="w-10" /></TableRow></TableHeader>
                        <TableBody>
                            <TableEmpty v-if="!expenses.length" :colspan="4">Sin gastos registrados.</TableEmpty>
                            <TableRow v-for="e in expenses" :key="e.id">
                                <TableCell><p class="font-medium">{{ e.concept }}</p><p v-if="e.notes" class="text-xs text-muted-foreground">{{ e.notes }}</p></TableCell>
                                <TableCell class="whitespace-nowrap">{{ fmtDate(e.incurred_on) }}</TableCell>
                                <TableCell class="text-right whitespace-nowrap tabular-nums">{{ money(e.amount, e.currency) }}<p v-if="e.currency === 'UF'" class="text-xs text-muted-foreground">≈ {{ money(e.amount_clp) }}</p></TableCell>
                                <TableCell><Button variant="ghost" size="icon-sm" class="text-destructive" title="Eliminar" @click="delExpense = e"><Trash2 /></Button></TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </DataCard>
            </div>
        </div>
    </div>

    <InvoiceFormDialog v-model:open="newInvoice" :invoice="null" :defaults="{ client_id: service.client?.id, client_service_id: service.id }" :clients="clients" :services="svc" :tax-rate="taxRate" />

    <Dialog v-model:open="expOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Registrar gasto o costo</DialogTitle><DialogDescription>Solo lo ve el equipo con permiso: el cliente nunca accede a esta información.</DialogDescription></DialogHeader>
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveExpense">
                <FormField label="Concepto" for="e-c" required class="sm:col-span-2" :error="expForm.errors.concept"><Input id="e-c" v-model="expForm.concept" placeholder="Hosting anual, licencia, horas de diseño…" /></FormField>
                <FormField label="Moneda" for="e-cur"><NativeSelect id="e-cur" v-model="expForm.currency"><option value="CLP">Pesos (CLP)</option><option value="UF">UF</option></NativeSelect></FormField>
                <FormField label="Monto" for="e-a" required :error="expForm.errors.amount"><Input id="e-a" v-model="expForm.amount" type="number" min="0" :step="expForm.currency === 'UF' ? 0.01 : 1" /></FormField>
                <FormField label="Fecha" for="e-d" :error="expForm.errors.incurred_on"><Input id="e-d" v-model="expForm.incurred_on" type="date" /></FormField>
                <FormField label="Nota" for="e-n" :error="expForm.errors.notes"><Input id="e-n" v-model="expForm.notes" /></FormField>
                <DialogFooter class="gap-2 sm:col-span-2"><Button type="button" variant="outline" @click="expOpen = false">Cancelar</Button><Button type="submit" :disabled="expForm.processing"><Spinner v-if="expForm.processing" /> Guardar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!delExpense" title="Eliminar gasto" :description="`Se eliminará «${delExpense?.concept}».`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (delExpense = null)" @confirm="delExpense && router.delete(`/contracts/expenses/${delExpense.id}`, { preserveScroll: true }); delExpense = null" />
    <ConfirmDialog :open="toDelete" title="Eliminar servicio" :description="`Se eliminará «${service.name}» y sus cobros pendientes de emitir.`" confirm-label="Eliminar" @update:open="(v: boolean) => (toDelete = v)" @confirm="destroy" />
</template>
