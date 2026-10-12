<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { usePermissions } from '@/composables/usePermissions';
import { create, destroy, edit, index } from '@/routes/users';
import type { Paginated, RoleOption, UserRow } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Usuarios', href: index() }] },
});

const props = defineProps<{
    users: Paginated<UserRow>;
    roles: RoleOption[];
    filters: { q?: string; role?: string; status?: string };
}>();

const { can } = usePermissions();

const filters = ref({
    q: props.filters.q ?? '',
    role: props.filters.role ?? '',
    status: props.filters.status ?? '',
});
useDebouncedFilters(index().url, filters, ['users', 'filters']);

const toDelete = ref<UserRow | null>(null);
const confirmDelete = () => {
    if (!toDelete.value) return;
    router.delete(destroy(toDelete.value.id).url, {
        preserveScroll: true,
        onFinish: () => (toDelete.value = null),
    });
};

const formatDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('es-CL', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : 'Nunca';
</script>

<template>
    <Head title="Usuarios" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Usuarios"
            description="Personas con acceso al CRM, su rol y estado."
        >
            <template #actions>
                <Button v-if="can('users.create')" as-child>
                    <Link :href="create()"><Plus /> Nuevo usuario</Link>
                </Button>
            </template>
        </PageHeader>

        <DataCard>
            <div class="flex flex-col gap-3 border-b p-4 md:flex-row">
                <div class="relative flex-1">
                    <Search
                        class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="filters.q"
                        placeholder="Buscar por nombre o correo…"
                        class="pl-9"
                    />
                </div>
                <NativeSelect v-model="filters.role" class="md:w-52">
                    <option value="">Todos los roles</option>
                    <option v-for="r in roles" :key="r.id" :value="r.id">
                        {{ r.name }}
                    </option>
                </NativeSelect>
                <NativeSelect v-model="filters.status" class="md:w-44">
                    <option value="">Cualquier estado</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Inactivos</option>
                </NativeSelect>
            </div>

            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Usuario</TableHead>
                        <TableHead>Rol</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Último acceso</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableEmpty v-if="!users.data.length" :colspan="5">
                        No hay usuarios que coincidan con la búsqueda.
                    </TableEmpty>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell>
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground"
                                >
                                    <UserRound class="size-4" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate font-medium">
                                        {{ user.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{ user.email
                                        }}<template v-if="user.job_title">
                                            · {{ user.job_title }}</template
                                        >
                                    </p>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell>
                            <Badge variant="outline">
                                {{ user.role?.name ?? 'Sin rol' }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :class="
                                    user.is_active
                                        ? 'border-transparent bg-brand-green/10 text-brand-green'
                                        : 'border-transparent bg-muted text-muted-foreground'
                                "
                            >
                                {{ user.is_active ? 'Activo' : 'Inactivo' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-muted-foreground">
                            {{ formatDate(user.last_login_at) }}
                        </TableCell>
                        <TableCell>
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="can('users.update')"
                                    variant="ghost"
                                    size="icon-sm"
                                    as-child
                                    title="Editar"
                                >
                                    <Link :href="edit(user.id)"
                                        ><Pencil
                                    /></Link>
                                </Button>
                                <Button
                                    v-if="can('users.delete')"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    title="Eliminar"
                                    @click="toDelete = user"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="users" />
        </DataCard>
    </div>

    <ConfirmDialog
        :open="!!toDelete"
        title="Eliminar usuario"
        :description="`Se eliminará a ${toDelete?.name}. Sus prospectos quedarán sin responsable.`"
        confirm-label="Eliminar"
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="confirmDelete"
    />
</template>
