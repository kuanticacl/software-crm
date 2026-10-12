<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlarmClock, CircleAlert, Landmark, Plus, Search, Settings2, Wallet } from '@lucide/vue';
import { ref } from 'vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import BankAccountsDialog from '@/components/billing/BankAccountsDialog.vue';
import InvoiceFormDialog from '@/components/billing/InvoiceFormDialog.vue';
import InvoiceList from '@/components/billing/InvoiceList.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { usePermissions } from '@/composables/usePermissions';
import { money, offsetLabel } from '@/lib/billingUi';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { InvoiceRow } from '@/types/billing';

defineOptions({ layout: { breadcrumbs: [{ title: 'Cobros', href: '/billing' }] } });

const props = defineProps<{
    invoices: Paginated<InvoiceRow>;
    filters: { status: string; client?: string | null; q?: string | null };
    counts: { scheduled: number; pending: number; overdue: number; paid: number };
    kpis: { receivable_clp: number; overdue_clp: number; paid_month_clp: number };
    clients: { id: number; name: string }[];
    services: { id: number; name: string; client_id: number; currency: string; price: number }[];
    taxRate: number;
    reminderOffsets: number[];
    aiAvailable: boolean;
    bank: { accounts: { bank: string; account_type: string; number: string; holder: string; tax_id: string; email: string }[]; note: string; types: string[] };
}>();

const { can } = usePermissions();
const filters = ref({ status: props.filters.status ?? 'pending', client: props.filters.client ?? '', q: props.filters.q ?? '' });
useDebouncedFilters('/billing', filters, ['invoices', 'filters', 'counts', 'kpis']);

const tabs = [
    { key: 'pending', label: 'Por pagar', count: () => props.counts.pending },
    { key: 'overdue', label: 'Vencidas', count: () => props.counts.overdue, danger: true },
    { key: 'scheduled', label: 'Por emitir', count: () => props.counts.scheduled },
    { key: 'paid', label: 'Pagadas', count: () => props.counts.paid },
    { key: 'cancelled', label: 'Anuladas', count: () => null },
    { key: '', label: 'Todas', count: () => null },
];

const cards = [
    { label: 'Por cobrar', value: () => money(props.kpis.receivable_clp), icon: Wallet, color: '#4A8CFF' },
    { label: 'Vencido', value: () => money(props.kpis.overdue_clp), icon: CircleAlert, color: '#EF4444' },
    { label: 'Cobrado este mes', value: () => money(props.kpis.paid_month_clp), icon: AlarmClock, color: '#A9606B' },
];

const newOpen = ref(false);
const bankOpen = ref(false);

// ---- recordatorios por defecto ----
const settingsOpen = ref(false);
const settingsForm = useForm({ reminder_offsets: [...props.reminderOffsets] as number[] });
const offsetsText = ref(props.reminderOffsets.join(', '));
const saveSettings = () => {
    settingsForm.reminder_offsets = offsetsText.value.split(',').map((x) => parseInt(x.trim(), 10)).filter((n) => Number.isFinite(n));
    settingsForm.put('/billing/settings', { preserveScroll: true, onSuccess: () => (settingsOpen.value = false) });
};
void router;
</script>

<template>
    <Head title="Cobros" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Cobros y boletas" description="Cobros y facturas de tus clientes. La factura se emite en tu sistema externo: aquí adjuntas el PDF, envías el cobro y controlas los pagos.">
            <template #actions>
                <Button v-if="can('billing.settings')" variant="outline" @click="bankOpen = true"><Landmark /> Datos bancarios</Button>
                <Button v-if="can('billing.settings')" variant="outline" @click="settingsOpen = true"><Settings2 /> Recordatorios</Button>
                <Button v-if="can('billing.manage')" @click="newOpen = true"><Plus /> Nuevo cobro</Button>
            </template>
        </PageHeader>

        <div v-if="!bank.accounts.length && can('billing.settings')" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-300">
            <p><strong>Aún no cargas tus datos bancarios.</strong> Agrégalos para que tus clientes vean cómo transferirte en el portal y en los correos de cobro.</p>
            <Button size="sm" variant="outline" @click="bankOpen = true"><Landmark /> Cargar datos</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <DataCard v-for="c in cards" :key="c.label" class="flex items-center gap-4 p-4">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-xl" :style="{ backgroundColor: c.color + '1f', color: c.color }"><component :is="c.icon" class="size-5" /></div>
                <div class="min-w-0"><p class="text-xs text-muted-foreground">{{ c.label }}</p><p class="text-xl font-semibold tabular-nums">{{ c.value() }}</p></div>
            </DataCard>
        </div>

        <DataCard>
            <div class="flex gap-1 overflow-x-auto border-b p-2">
                <button
                    v-for="t in tabs" :key="t.key" type="button"
                    :class="cn('inline-flex shrink-0 items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-medium transition-colors', filters.status === t.key ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted')"
                    @click="filters.status = t.key"
                >
                    {{ t.label }}
                    <span v-if="t.count() !== null && t.count() !== 0" :class="cn('rounded-full px-1.5 text-[11px] font-semibold', t.danger ? 'bg-red-500/15 text-red-600' : 'bg-muted text-muted-foreground')">{{ t.count() }}</span>
                </button>
            </div>
            <div class="grid gap-3 border-b p-4 sm:grid-cols-[1fr_16rem]">
                <div class="relative"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="filters.q" placeholder="Buscar por N° de factura, concepto o cliente…" class="pl-9" /></div>
                <NativeSelect v-model="filters.client"><option value="">Todos los clientes</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></NativeSelect>
            </div>
            <InvoiceList :invoices="invoices.data" show-client :clients="clients" :services="services" :tax-rate="taxRate" :ai-available="aiAvailable" />
            <Pagination :paginator="invoices" />
        </DataCard>
    </div>

    <InvoiceFormDialog v-model:open="newOpen" :invoice="null" :clients="clients" :services="services" :tax-rate="taxRate" :ai-available="aiAvailable" />

    <BankAccountsDialog v-model:open="bankOpen" :accounts="bank.accounts" :note="bank.note" :types="bank.types" />

    <Dialog v-model:open="settingsOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Recordatorios automáticos de pago</DialogTitle>
                <DialogDescription>Aplican a los cobros recurrentes por pagar (cada servicio puede definir los suyos). Se envían a las 9:00 (hora de Chile) y se detienen apenas la factura se marca pagada.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="saveSettings">
                <FormField label="Días respecto del vencimiento" for="offs" hint="Separados por coma. Negativo = antes. Ej: -5, 0, 3, 7" :error="settingsForm.errors.reminder_offsets"><Input id="offs" v-model="offsetsText" /></FormField>
                <ul class="space-y-1 text-xs text-muted-foreground">
                    <li v-for="o in offsetsText.split(',').map((x) => parseInt(x.trim(), 10)).filter((n) => Number.isFinite(n))" :key="o">• {{ offsetLabel(o) }}</li>
                </ul>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="settingsOpen = false">Cancelar</Button><Button type="submit" :disabled="settingsForm.processing"><Spinner v-if="settingsForm.processing" /> Guardar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
