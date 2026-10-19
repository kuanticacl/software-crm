<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, Globe, Mail, MapPin, Pencil, Phone } from '@lucide/vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import { edit, index } from '@/routes/clients';
import type { ClientRow } from '@/types';

type LeadRow = {
    id: number;
    full_name: string;
    email: string | null;
    stage: { name: string; color: string };
    source: { name: string; color: string };
    assignee: string | null;
    created_at: string;
};

const props = defineProps<{ client: ClientRow; leads: LeadRow[] }>();
const { can } = usePermissions();

defineOptions({ layout: { breadcrumbs: [{ title: 'Empresas', href: index() }] } });

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
        <PageHeader :title="client.name" :description="[client.legal_name, client.tax_id].filter(Boolean).join(' · ') || undefined">
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
                    <div v-if="client.customer_id"><dt class="text-xs text-muted-foreground">ID de cliente (para la app y el sitio)</dt><dd class="font-mono text-xs">{{ client.customer_id }}</dd></div>
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
                <div class="border-b px-5 py-3 font-semibold">Leads asociados ({{ leads.length }})</div>
                <Table>
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead>Cliente</TableHead><TableHead>Origen</TableHead><TableHead>Etapa</TableHead><TableHead>Responsable</TableHead><TableHead>Ingreso</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableEmpty v-if="!leads.length" :colspan="5">Esta empresa aún no tiene clientes asociados.</TableEmpty>
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
    </div>
</template>
