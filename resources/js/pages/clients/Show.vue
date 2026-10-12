<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, Globe, Mail, MapPin, Pencil, Phone, Plus } from '@lucide/vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import { edit, index } from '@/routes/clients';
import type { ClientRow } from '@/types';
import type { InvoiceRow, ServiceRow } from '@/types/billing';
import InvoiceList from '@/components/billing/InvoiceList.vue';
import PortalAccessCard from '@/components/billing/PortalAccessCard.vue';
import StatusPill from '@/components/billing/StatusPill.vue';
import { cycleLabels, cyclePer, fmtDate as fmtD, money } from '@/lib/billingUi';

type LeadRow = {
    id: number;
    full_name: string;
    email: string | null;
    stage: { name: string; color: string };
    source: { name: string; color: string };
    assignee: string | null;
    created_at: string;
};

const props = defineProps<{
    client: ClientRow;
    leads: LeadRow[];
    services: ServiceRow[] | null;
    invoices: InvoiceRow[] | null;
    portal: { url: string; users: { id: number; name: string; email: string; is_active: boolean; must_change_password: boolean; last_login_at: string | null; invited_at: string | null }[] } | null;
    billingLookups: { clients: { id: number; name: string }[]; services: { id: number; name: string; client_id: number; currency: string; price: number }[]; taxRate: number };
}>();
const { can } = usePermissions();

defineOptions({ layout: { breadcrumbs: [{ title: 'Clientes', href: index() }] } });

const fmt = (d: string) => new Intl.DateTimeFormat('es-CL', { dateStyle: 'medium' }).format(new Date(d));
const info = [
    { icon: Mail, value: props.client.email },
    { icon: Phone, value: props.client.phone },
    { icon: Globe, value: props.client.website },
    { icon: MapPin, value: [props.client.address, props.client.commune, props.client.city].filter(Boolean).join(', ') },
].filter((i) => i.value);
</script>

<template>
    <Head :title="client.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="client.name" :description="[client.kind === 'company' ? 'Empresa' : 'Persona', client.legal_name, client.tax_id].filter(Boolean).join(' · ') || undefined">
            <template #actions>
                <Badge :class="client.is_active ? 'border-transparent bg-brand-green/10 text-brand-green' : 'border-transparent bg-muted text-muted-foreground'">
                    {{ client.is_active ? 'Activo' : 'Inactivo' }}
                </Badge>
                <Button v-if="can('clients.update')" variant="outline" as-child>
                    <Link :href="edit(client.id)"><Pencil /> Editar</Link>
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <DataCard class="h-fit p-5">
                <div class="mb-4 flex items-center gap-3">
                    <div class="flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground"><Building2 /></div>
                    <div>
                        <p class="text-xs text-muted-foreground">RUT</p>
                        <p class="font-medium">{{ client.tax_id ?? '—' }}</p>
                    </div>
                </div>
                <dl class="mb-4 grid gap-2 text-sm">
                    <div v-if="client.legal_name"><dt class="text-xs text-muted-foreground">Razón social</dt><dd class="font-medium">{{ client.legal_name }}</dd></div>
                    <div v-if="client.activity"><dt class="text-xs text-muted-foreground">Giro</dt><dd>{{ client.activity }}</dd></div>
                    <div v-if="client.contact_name"><dt class="text-xs text-muted-foreground">Contacto</dt><dd>{{ client.contact_name }}<span v-if="client.contact_role" class="text-muted-foreground"> · {{ client.contact_role }}</span></dd></div>
                </dl>
                <ul class="grid gap-3 text-sm">
                    <li v-for="i in info" :key="i.value as string" class="flex items-start gap-2.5">
                        <component :is="i.icon" class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <span class="break-all">{{ i.value }}</span>
                    </li>
                </ul>
                <div v-if="client.notes_html" class="rich mt-4 border-t pt-4 text-sm text-muted-foreground" v-html="client.notes_html" />
            </DataCard>

            <DataCard class="lg:col-span-2">
                <div class="border-b px-5 py-3 font-semibold">Prospectos asociados ({{ leads.length }})</div>
                <Table>
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead>Lead</TableHead><TableHead>Origen</TableHead><TableHead>Etapa</TableHead><TableHead>Responsable</TableHead><TableHead>Ingreso</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="!leads.length" :colspan="5">Este cliente aún no tiene prospectos asociados.</TableEmpty>
                        <TableRow v-for="l in leads" :key="l.id">
                            <TableCell><p class="font-medium">{{ l.full_name }}</p><p class="text-xs text-muted-foreground">{{ l.email }}</p></TableCell>
                            <TableCell><span class="inline-flex items-center gap-1.5 text-sm"><span class="size-2 rounded-full" :style="{ backgroundColor: l.source.color }" />{{ l.source.name }}</span></TableCell>
                            <TableCell><span class="inline-flex items-center gap-1.5 text-sm"><span class="size-2 rounded-full" :style="{ backgroundColor: l.stage.color }" />{{ l.stage.name }}</span></TableCell>
                            <TableCell class="text-muted-foreground">{{ l.assignee ?? 'Sin asignar' }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ fmt(l.created_at) }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </DataCard>
        </div>

        <PortalAccessCard v-if="portal" :client-id="client.id" :portal="portal" :contacts="leads.filter((l) => l.email)" />

        <DataCard v-if="services !== null">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
                <span class="font-semibold">Servicios ({{ services.length }})</span>
                <Button v-if="can('contracts.create')" size="sm" variant="outline" as-child><Link :href="`/contracts/create?client=${client.id}`"><Plus /> Nuevo servicio</Link></Button>
            </div>
            <Table>
                <TableHeader><TableRow class="hover:bg-transparent"><TableHead>Servicio</TableHead><TableHead class="hidden sm:table-cell">Valor neto</TableHead><TableHead class="hidden md:table-cell">Vigencia</TableHead><TableHead>Estado</TableHead></TableRow></TableHeader>
                <TableBody>
                    <TableEmpty v-if="!services.length" :colspan="4">Sin servicios contratados.</TableEmpty>
                    <TableRow v-for="sv in services" :key="sv.id">
                        <TableCell><Link :href="`/contracts/${sv.id}`" class="font-medium hover:text-primary">{{ sv.name }}</Link><p class="text-xs text-muted-foreground">{{ cycleLabels[sv.billing_cycle] }}<span v-if="sv.parent"> · asociado a {{ sv.parent.name }}</span></p></TableCell>
                        <TableCell class="hidden whitespace-nowrap tabular-nums sm:table-cell">{{ money(sv.price, sv.currency) }} <span class="text-xs text-muted-foreground">{{ cyclePer[sv.billing_cycle] }}</span></TableCell>
                        <TableCell class="hidden text-sm md:table-cell">{{ fmtD(sv.start_date) }} → {{ sv.end_date ? fmtD(sv.end_date) : 'sin término' }}<p v-if="sv.auto_renew" class="text-xs text-brand-green">Renovación automática</p></TableCell>
                        <TableCell><StatusPill kind="service" :status="sv.status" /></TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </DataCard>

        <DataCard v-if="invoices !== null">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3"><span class="font-semibold">Cobros y facturas</span><Button v-if="can('billing.view')" size="sm" variant="ghost" as-child><Link :href="`/billing?client=${client.id}&status=`">Ver en Facturación</Link></Button></div>
            <InvoiceList :invoices="invoices" :clients="billingLookups.clients" :services="billingLookups.services" :tax-rate="billingLookups.taxRate" empty-text="Sin cobros registrados." />
        </DataCard>
    </div>
</template>
