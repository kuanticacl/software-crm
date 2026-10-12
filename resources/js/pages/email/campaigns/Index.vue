<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Copy, Eye, Mail, MousePointerClick, Pencil, Plus, Search, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { usePermissions } from '@/composables/usePermissions';
import { formatDateTime } from '@/lib/format';
import { campaignTone } from '@/lib/emailUi';
import { create, destroy, duplicate, edit, index, show } from '@/routes/campaigns';
import type { Paginated } from '@/types';

type Row = { id: number; name: string; subject: string; status: string; recipients_count: number; scheduled_at: string | null; finished_at: string | null; created_at: string; template: string | null; stats: Record<string, number> };
const props = defineProps<{ campaigns: Paginated<Row>; filters: { q?: string; status?: string }; statuses: Record<string, string>; summary: { sent_30d: number; campaigns_30d: number; suppressed: number } }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Boletines', href: index() }] } });

const { can } = usePermissions();
const filters = ref({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
useDebouncedFilters(index().url, filters, ['campaigns', 'filters']);

const toDelete = ref<Row | null>(null);
const confirmDelete = () => toDelete.value && router.delete(destroy(toDelete.value.id).url, { preserveScroll: true, onFinish: () => (toDelete.value = null) });
const when = (c: Row) => (c.status === 'scheduled' && c.scheduled_at ? `Programado ${formatDateTime(c.scheduled_at)}` : c.finished_at ? formatDateTime(c.finished_at) : formatDateTime(c.created_at));
</script>

<template>
    <Head title="Boletines" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Boletines" description="Crea, programa y mide envíos masivos a listas, prospectos y clientes.">
            <template #actions><Button v-if="can('campaigns.create')" as-child><Link :href="create()"><Plus /> Nuevo boletín</Link></Button></template>
        </PageHeader>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border bg-card p-4"><p class="text-2xl font-bold tabular-nums">{{ summary.sent_30d.toLocaleString('es-CL') }}</p><p class="text-xs text-muted-foreground">Correos enviados (30 días)</p></div>
            <div class="rounded-2xl border bg-card p-4"><p class="text-2xl font-bold tabular-nums">{{ summary.campaigns_30d }}</p><p class="text-xs text-muted-foreground">Boletines creados (30 días)</p></div>
            <div class="rounded-2xl border bg-card p-4"><p class="text-2xl font-bold tabular-nums">{{ summary.suppressed.toLocaleString('es-CL') }}</p><p class="text-xs text-muted-foreground">Direcciones excluidas (bajas, rebotes, spam)</p></div>
        </div>

        <DataCard>
            <div class="flex flex-col gap-3 border-b p-4 md:flex-row">
                <div class="relative flex-1"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="filters.q" placeholder="Buscar por nombre o asunto…" class="pl-9" /></div>
                <NativeSelect v-model="filters.status" class="md:w-52"><option value="">Todos los estados</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></NativeSelect>
            </div>
            <Table>
                <TableHeader><TableRow class="hover:bg-transparent"><TableHead>Boletín</TableHead><TableHead>Estado</TableHead><TableHead class="text-right">Destinat.</TableHead><TableHead class="text-right">Apertura</TableHead><TableHead class="text-right">Clics</TableHead><TableHead class="text-right">Rebotes</TableHead><TableHead class="text-right">Acciones</TableHead></TableRow></TableHeader>
                <TableBody>
                    <TableEmpty v-if="!campaigns.data.length" :colspan="7"><Mail class="mx-auto mb-2 size-6" />Aún no hay boletines.</TableEmpty>
                    <TableRow v-for="c in campaigns.data" :key="c.id">
                        <TableCell>
                            <Link :href="show(c.id)" class="block min-w-0"><p class="truncate font-medium hover:text-primary">{{ c.name }}</p><p class="truncate text-xs text-muted-foreground">{{ c.subject }} · {{ when(c) }}</p></Link>
                        </TableCell>
                        <TableCell><Badge :class="['border-transparent', campaignTone[c.status]]">{{ statuses[c.status] }}</Badge></TableCell>
                        <TableCell class="text-right tabular-nums">{{ c.recipients_count.toLocaleString('es-CL') }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ c.stats.sent ? c.stats.open_rate + '%' : '—' }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ c.stats.sent ? c.stats.click_rate + '%' : '—' }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ c.stats.bounced || '—' }}</TableCell>
                        <TableCell>
                            <div class="flex justify-end gap-0.5">
                                <Button variant="ghost" size="icon-sm" as-child title="Ver resultados"><Link :href="show(c.id)"><Eye /></Link></Button>
                                <Button v-if="can('campaigns.create') && ['draft', 'scheduled'].includes(c.status)" variant="ghost" size="icon-sm" as-child title="Editar"><Link :href="edit(c.id)"><Pencil /></Link></Button>
                                <Button v-if="can('campaigns.create')" variant="ghost" size="icon-sm" title="Duplicar" @click="router.post(duplicate(c.id).url)"><Copy /></Button>
                                <Button v-if="can('campaigns.create') && c.status !== 'sending'" variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" @click="toDelete = c"><Trash2 /></Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="campaigns" />
        </DataCard>
    </div>

    <ConfirmDialog :open="!!toDelete" title="Eliminar boletín" :description="`Se eliminará «${toDelete?.name}» y su historial de envíos.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="confirmDelete" />
</template>
