<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Eye, Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeleteWithRelatedDialog from '@/components/DeleteWithRelatedDialog.vue';
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
import { create, destroy, edit, index, show } from '@/routes/clients';
import type { ClientRow, Paginated } from '@/types';

defineOptions({ layout: { breadcrumbs: [{ title: 'Empresas', href: index() }] } });

const props = defineProps<{
    clients: Paginated<ClientRow>;
    filters: { q?: string; status?: string };
    transferTargets: { id: number; name: string }[];
}>();

const { can } = usePermissions();

const filters = ref({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
useDebouncedFilters(index().url, filters, ['clients', 'filters']);

const toDelete = ref<ClientRow | null>(null);
const deleteSummary = computed(() => {
    const c = toDelete.value;
    if (!c) return '';
    const n = (k: number | undefined, one: string, many: string) => (k ? `${k} ${k === 1 ? one : many}` : '');
    return [n(c.leads_count, 'lead', 'leads'), n(c.proposals_count, 'propuesta', 'propuestas')].filter(Boolean).join(' y ');
});
const hasRelated = computed(() => !!toDelete.value && ((toDelete.value.leads_count ?? 0) > 0 || (toDelete.value.proposals_count ?? 0) > 0));
const deleteTargets = computed(() => props.transferTargets.filter((c) => c.id !== toDelete.value?.id));
const confirmDelete = (extra: { transfer_to?: number | null } = {}) => {
    if (!toDelete.value) return;
    router.delete(destroy(toDelete.value.id).url, {
        data: { confirm_related: 1, ...(extra.transfer_to ? { transfer_to: extra.transfer_to } : {}) },
        preserveScroll: true,
        onFinish: () => (toDelete.value = null),
    });
};
</script>

<template>
    <Head title="Empresas" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Empresas" description="Base de datos de talleres y empresas con las que trabajamos.">
            <template #actions>
                <Button v-if="can('clients.create')" as-child>
                    <Link :href="create()"><Plus /> Nueva empresa</Link>
                </Button>
            </template>
        </PageHeader>

        <DataCard>
            <div class="flex flex-col gap-3 border-b p-4 md:flex-row">
                <div class="relative flex-1">
                    <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="filters.q" placeholder="Buscar por nombre, razón social, RUT o correo…" class="pl-9" />
                </div>
                <NativeSelect v-model="filters.status" class="md:w-44">
                    <option value="">Cualquier estado</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Inactivos</option>
                </NativeSelect>
            </div>

            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Empresa</TableHead>
                        <TableHead>RUT</TableHead>
                        <TableHead>Contacto</TableHead>
                        <TableHead>Clientes</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="!clients.data.length" :colspan="6">No hay empresas que coincidan.</TableEmpty>
                    <TableRow v-for="c in clients.data" :key="c.id">
                        <TableCell>
                            <Link :href="show(c.id)" class="flex items-center gap-3">
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                                    <Building2 class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate font-medium hover:text-primary">{{ c.name }}</p>
                                    <p v-if="c.legal_name" class="truncate text-xs text-muted-foreground">{{ c.legal_name }}</p>
                                </div>
                            </Link>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ c.tax_id ?? '—' }}</TableCell>
                        <TableCell>
                            <p class="text-sm">{{ c.email ?? '—' }}</p>
                            <p class="text-xs text-muted-foreground">{{ c.phone }}</p>
                        </TableCell>
                        <TableCell>{{ c.leads_count }}</TableCell>
                        <TableCell>
                            <Badge :class="c.is_active ? 'border-transparent bg-brand-green/10 text-brand-green' : 'border-transparent bg-muted text-muted-foreground'">
                                {{ c.is_active ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="icon-sm" as-child title="Ver ficha"><Link :href="show(c.id)"><Eye /></Link></Button>
                                <Button v-if="can('clients.update')" variant="ghost" size="icon-sm" as-child title="Editar"><Link :href="edit(c.id)"><Pencil /></Link></Button>
                                <Button v-if="can('clients.delete')" variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" @click="toDelete = c"><Trash2 /></Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="clients" />
        </DataCard>
    </div>

    <ConfirmDialog
        :open="!!toDelete && !hasRelated"
        title="Eliminar empresa"
        :description="`Se eliminará «${toDelete?.name}».`"
        confirm-label="Eliminar"
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="confirmDelete()"
    />
    <DeleteWithRelatedDialog
        :open="!!toDelete && hasRelated"
        :title="`Eliminar empresa «${toDelete?.name}»`"
        :summary="deleteSummary"
        :targets="deleteTargets"
        transfer-label="Transferirlos a otra empresa"
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="(o) => confirmDelete(o)"
    />
</template>
