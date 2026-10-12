<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarClock, Eye, Layers, PackageCheck, Pencil, Plus, Repeat, Search } from '@lucide/vue';
import { ref } from 'vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatusPill from '@/components/billing/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { usePermissions } from '@/composables/usePermissions';
import { cycleLabels, cyclePer, fmtDate, money } from '@/lib/billingUi';
import type { Paginated } from '@/types';
import type { ServiceRow } from '@/types/billing';

defineOptions({ layout: { breadcrumbs: [{ title: 'Proyectos', href: '/contracts' }] } });

const props = defineProps<{
    services: Paginated<ServiceRow>;
    filters: { q?: string; client?: string; status?: string; cycle?: string };
    clients: { id: number; name: string }[];
    kpis: { active: number; recurring_monthly_clp: number; ending_soon: number };
    meta: { cycles: Record<string, string>; statuses: Record<string, string> };
}>();

const { can } = usePermissions();
const filters = ref({ q: props.filters.q ?? '', client: props.filters.client ?? '', status: props.filters.status ?? '', cycle: props.filters.cycle ?? '' });
useDebouncedFilters('/contracts', filters, ['services', 'filters']);

const cards = [
    { label: 'Servicios activos', value: () => String(props.kpis.active), icon: PackageCheck, color: '#6419DB' },
    { label: 'Ingreso recurrente mensual (neto)', value: () => money(props.kpis.recurring_monthly_clp), icon: Repeat, color: '#4A8CFF' },
    { label: 'Terminan en 30 días (sin renovación)', value: () => String(props.kpis.ending_soon), icon: CalendarClock, color: '#FFA165' },
];
</script>

<template>
    <Head title="Proyectos" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Proyectos" description="Lo que cada cliente tiene contratado: honorarios, obra, fechas y cobros.">
            <template #actions>
                <Button v-if="can('contracts.create')" as-child>
                    <Link href="/contracts/create"><Plus /> Nuevo servicio</Link>
                </Button>
            </template>
        </PageHeader>

        <div class="grid gap-3 sm:grid-cols-3">
            <DataCard v-for="c in cards" :key="c.label" class="flex items-center gap-4 p-4">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-xl" :style="{ backgroundColor: c.color + '1f', color: c.color }"><component :is="c.icon" class="size-5" /></div>
                <div class="min-w-0">
                    <p class="truncate text-xs text-muted-foreground">{{ c.label }}</p>
                    <p class="text-xl font-semibold tabular-nums">{{ c.value() }}</p>
                </div>
            </DataCard>
        </div>

        <DataCard>
            <div class="grid gap-3 border-b p-4 sm:grid-cols-2 lg:grid-cols-[1fr_14rem_11rem_11rem]">
                <div class="relative">
                    <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="filters.q" placeholder="Buscar servicio o cliente…" class="pl-9" />
                </div>
                <NativeSelect v-model="filters.client">
                    <option value="">Todos los clientes</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                </NativeSelect>
                <NativeSelect v-model="filters.status">
                    <option value="">Cualquier estado</option>
                    <option v-for="(l, k) in meta.statuses" :key="k" :value="k">{{ l }}</option>
                </NativeSelect>
                <NativeSelect v-model="filters.cycle">
                    <option value="">Cualquier ciclo</option>
                    <option v-for="(l, k) in meta.cycles" :key="k" :value="k">{{ l }}</option>
                </NativeSelect>
            </div>

            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Servicio</TableHead>
                        <TableHead class="hidden md:table-cell">Cliente</TableHead>
                        <TableHead>Valor neto</TableHead>
                        <TableHead class="hidden lg:table-cell">Vigencia</TableHead>
                        <TableHead class="hidden xl:table-cell">Próximo cobro</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="!services.data.length" :colspan="7">Aún no hay servicios contratados con estos filtros.</TableEmpty>
                    <TableRow v-for="s in services.data" :key="s.id">
                        <TableCell>
                            <Link :href="`/contracts/${s.id}`" class="block min-w-0">
                                <p class="truncate font-medium hover:text-primary">{{ s.name }}</p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ cycleLabels[s.billing_cycle] }}
                                    <span v-if="s.parent"> · asociado a {{ s.parent.name }}</span>
                                    <span v-if="s.children_count"> · <Layers class="inline size-3" /> {{ s.children_count }} asociado{{ s.children_count === 1 ? '' : 's' }}</span>
                                </p>
                                <p class="truncate text-xs text-muted-foreground md:hidden">{{ s.client?.name }}</p>
                            </Link>
                        </TableCell>
                        <TableCell class="hidden md:table-cell"><Link v-if="s.client" :href="`/clients/${s.client.id}`" class="hover:text-primary">{{ s.client.name }}</Link></TableCell>
                        <TableCell class="whitespace-nowrap tabular-nums">{{ money(s.price, s.currency) }} <span class="text-xs text-muted-foreground">{{ cyclePer[s.billing_cycle] }}</span></TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            {{ fmtDate(s.start_date) }} → {{ s.end_date ? fmtDate(s.end_date) : 'sin término' }}
                            <p v-if="s.auto_renew" class="text-xs text-brand-green">Renovación automática</p>
                        </TableCell>
                        <TableCell class="hidden text-sm xl:table-cell">{{ fmtDate(s.next_charge_on) }}</TableCell>
                        <TableCell><StatusPill kind="service" :status="s.status" /></TableCell>
                        <TableCell>
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="icon-sm" as-child title="Ver detalle"><Link :href="`/contracts/${s.id}`"><Eye /></Link></Button>
                                <Button v-if="can('contracts.update')" variant="ghost" size="icon-sm" as-child title="Editar"><Link :href="`/contracts/${s.id}/edit`"><Pencil /></Link></Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="services" />
        </DataCard>
    </div>
</template>
