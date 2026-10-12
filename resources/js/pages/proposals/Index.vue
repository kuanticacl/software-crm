<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileSignature, Package, Plus, Search } from '@lucide/vue';
import { ref } from 'vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { formatAmount, formatMoneyShort } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { create, index, show } from '@/routes/proposals';
import { index as services } from '@/routes/services';
import type { Paginated, ProposalRow } from '@/types';

const props = defineProps<{
    proposals: Paginated<ProposalRow>;
    filters: { q: string; status: string };
    statuses: Record<string, string>;
    summary: Record<string, { label: string; count: number; net: number }>;
    can: { create: boolean; services: boolean };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Propuestas', href: index() }] } });

const filters = ref({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
useDebouncedFilters(index().url, filters, ['proposals', 'filters']);

const colors: Record<string, string> = { draft: '#8A8A8A', sent: '#1AA0E4', viewed: '#3B2F2F', changes_requested: '#D97706', accepted: '#0D9F85', rejected: '#DC2626', expired: '#C23F00' };
const toggle = (k: string) => (filters.value.status = filters.value.status === k ? '' : k);
const fmt = (d: string | null) => (d ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'medium' }).format(new Date(d + 'T12:00:00')) : '—');
</script>

<template>
    <Head title="Propuestas" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Propuestas comerciales" description="Arma propuestas con la identidad de Vivienda en Regla, envíalas por correo o enlace y sigue si el cliente las vio y aceptó.">
            <template #actions>
                <Button v-if="can.services" variant="outline" as-child><Link :href="services()"><Package /> Catálogo</Link></Button>
                <Button v-if="can.create" as-child><Link :href="create()"><Plus /> Nueva propuesta</Link></Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <button v-for="(s, k) in summary" :key="k" type="button" :class="cn('rounded-2xl border bg-card p-3 text-left transition hover:border-primary/40', filters.status === k && 'border-primary ring-2 ring-primary/30')" @click="toggle(String(k))">
                <span class="flex items-center gap-2 text-xs text-muted-foreground"><span class="size-2 rounded-full" :style="{ backgroundColor: colors[k] }" />{{ s.label }}</span>
                <span class="mt-1 block text-2xl font-bold">{{ s.count }}</span>
                <span class="text-[11px] text-muted-foreground">{{ s.net ? formatMoneyShort(s.net) + ' neto' : '—' }}</span>
            </button>
        </div>

        <DataCard>
            <div class="border-b p-4">
                <div class="relative max-w-md">
                    <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="filters.q" placeholder="Buscar por título, número o cliente…" class="pl-9" />
                </div>
            </div>
            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Propuesta</TableHead><TableHead>Cliente</TableHead><TableHead>Estado</TableHead><TableHead class="text-right">Total neto</TableHead><TableHead>Vigencia</TableHead><TableHead>Responsable</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="!proposals.data.length" :colspan="6">
                        <FileSignature class="mx-auto mb-2 size-6 text-muted-foreground" />Aún no hay propuestas{{ filters.q || filters.status ? ' con esos filtros' : '' }}.
                    </TableEmpty>
                    <TableRow v-for="p in proposals.data" :key="p.id" class="cursor-pointer" @click="router.visit(show(p.id).url)">
                        <TableCell>
                            <p class="font-medium">{{ p.title }}</p>
                            <p class="text-xs text-muted-foreground">{{ p.number }}<template v-if="p.lead"> · lead {{ p.lead.name }}</template></p>
                        </TableCell>
                        <TableCell>{{ p.company ?? '—' }}<p v-if="p.contact" class="text-xs text-muted-foreground">{{ p.contact }}</p></TableCell>
                        <TableCell><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: p.status_color }">{{ p.status_label }}</span></TableCell>
                        <TableCell class="text-right font-semibold">
                            {{ formatAmount(p.total_net, p.currency) }}
                            <p v-if="p.total_monthly" class="text-[11px] font-normal text-muted-foreground">{{ formatAmount(p.total_monthly, p.currency) }}/mes</p>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ fmt(p.valid_until) }}</TableCell>
                        <TableCell class="text-muted-foreground">{{ p.owner ?? '—' }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="proposals" />
        </DataCard>
    </div>
</template>
