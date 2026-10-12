<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, Plus, Search, X } from '@lucide/vue';
import { ref } from 'vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import SourceIcon from '@/components/SourceIcon.vue';
import UserInitials from '@/components/UserInitials.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate } from '@/lib/format';
import { create, index, show } from '@/routes/leads';
import type { LeadCard, Paginated, StageRef, SourceRef, UserOption } from '@/types';

defineOptions({ layout: { breadcrumbs: [{ title: 'Prospectos', href: index() }] } });

const props = defineProps<{
    leads: Paginated<LeadCard & { stage: StageRef }>;
    filters: Record<string, string | undefined>;
    sources: Pick<SourceRef, 'id' | 'name' | 'color'>[];
    stages: StageRef[];
    users: UserOption[];
}>();

const { can } = usePermissions();

const filters = ref({
    q: props.filters.q ?? '',
    source: props.filters.source ?? '',
    stage: props.filters.stage ?? '',
    assignee: props.filters.assignee ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});
useDebouncedFilters(index().url, filters, ['leads', 'filters']);

const hasFilters = () => Object.values(filters.value).some(Boolean);
const clear = () => (filters.value = { q: '', source: '', stage: '', assignee: '', from: '', to: '' });
</script>

<template>
    <Head title="Prospectos" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Prospectos" description="Todos los prospectos que ingresan por API, formularios o carga manual.">
            <template #actions>
                <Button v-if="can('leads.create')" as-child>
                    <Link :href="create()"><Plus /> Nuevo prospecto</Link>
                </Button>
            </template>
        </PageHeader>

        <DataCard>
            <div class="grid gap-3 border-b p-4 md:grid-cols-2 xl:grid-cols-6">
                <div class="relative xl:col-span-2">
                    <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="filters.q" placeholder="Nombre, correo, teléfono o empresa…" class="pl-9" />
                </div>
                <NativeSelect v-model="filters.source">
                    <option value="">Todos los orígenes</option>
                    <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                </NativeSelect>
                <NativeSelect v-model="filters.stage">
                    <option value="">Todas las etapas</option>
                    <option v-for="s in stages" :key="s.id" :value="s.id">{{ s.name }}</option>
                </NativeSelect>
                <NativeSelect v-if="users.length" v-model="filters.assignee">
                    <option value="">Todos los responsables</option>
                    <option value="none">Sin asignar</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </NativeSelect>
                <div class="flex items-center gap-2 xl:col-span-2">
                    <Input v-model="filters.from" type="date" title="Desde" />
                    <span class="text-muted-foreground">–</span>
                    <Input v-model="filters.to" type="date" title="Hasta" />
                    <Button v-if="hasFilters()" variant="ghost" size="icon-sm" title="Limpiar filtros" @click="clear"><X /></Button>
                </div>
            </div>

            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Lead</TableHead>
                        <TableHead>Origen</TableHead>
                        <TableHead>Etapa</TableHead>
                        <TableHead>Responsable</TableHead>
                        <TableHead>Ingreso</TableHead>
                        <TableHead class="text-right">Ver</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="!leads.data.length" :colspan="6">No hay prospectos que coincidan con los filtros.</TableEmpty>
                    <TableRow v-for="l in leads.data" :key="l.id">
                        <TableCell>
                            <Link :href="show(l.id)" class="block">
                                <p class="font-medium hover:text-primary">{{ l.full_name }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ [l.email, l.company].filter(Boolean).join(' · ') }}
                                </p>
                            </Link>
                        </TableCell>
                        <TableCell>
                            <span
                                v-if="l.source"
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium text-white [&_svg]:size-3"
                                :style="{ backgroundColor: l.source.color }"
                            >
                                <SourceIcon :name="l.source.icon" />{{ l.source.name }}
                            </span>
                        </TableCell>
                        <TableCell>
                            <span class="inline-flex items-center gap-1.5 text-sm">
                                <span class="size-2.5 rounded-full" :style="{ backgroundColor: l.stage.color }" />{{ l.stage.name }}
                            </span>
                        </TableCell>
                        <TableCell>
                            <span class="inline-flex items-center gap-2 text-sm">
                                <UserInitials :name="l.assignee?.name" />
                                <span class="text-muted-foreground">{{ l.assignee?.name ?? 'Sin asignar' }}</span>
                            </span>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ formatDate(l.created_at) }}</TableCell>
                        <TableCell>
                            <div class="flex justify-end">
                                <Button variant="ghost" size="icon-sm" as-child title="Abrir ficha"><Link :href="show(l.id)"><Eye /></Link></Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="leads" />
        </DataCard>
    </div>
</template>
