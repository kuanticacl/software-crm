<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Calculator,
    Download,
    Eye,
    Lock,
    LockOpen,
    Pencil,
    Plus,
    Search,
    Tags,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import UnitDialog from '@/components/realestate/UnitDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { clp, m2, toClp, uf as fmtUf, type UnitRow } from '@/lib/realEstate';

const props = defineProps<{
    projects: { id: number; name: string; color: string }[];
    project: { id: number; name: string; color: string } | null;
    units: UnitRow[];
    models: string[];
    filters: { type?: string; status?: string; q?: string };
    uf: { value: number; date: string } | null;
    types: Record<string, string>;
    mainTypes: string[];
    statuses: Record<string, string>;
    statusColors: Record<string, string>;
    orientations: string[];
    can: { manage: boolean; status: boolean; quote: boolean };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Inventario', href: '/inventory' }] },
});

const f = reactive({
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
    q:
        new URLSearchParams(
            typeof window !== 'undefined' ? window.location.search : '',
        ).get('q') ??
        props.filters.q ??
        '',
    bedrooms: '',
});
const changeProject = (id: string) =>
    router.get('/inventory', { project: id }, { preserveState: false });

const rows = computed(() => {
    const q = f.q.trim().toLowerCase();
    return props.units.filter(
        (u) =>
            (!f.type || u.type === f.type) &&
            (!f.status || u.status === f.status) &&
            (!f.bedrooms || String(u.bedrooms ?? '') === f.bedrooms) &&
            (!q ||
                [
                    u.code,
                    u.model,
                    u.tower,
                    u.program_label,
                    props.types[u.type],
                ].some((v) => v && String(v).toLowerCase().includes(q))),
    );
});
const counts = computed(() =>
    Object.fromEntries(
        Object.keys(props.statuses).map((k) => [
            k,
            props.units.filter((u) => u.status === k).length,
        ]),
    ),
);
const bedroomOptions = computed(() =>
    [
        ...new Set(
            props.units.map((u) => u.bedrooms).filter((b) => b !== null),
        ),
    ].sort((a, b) => Number(a) - Number(b)),
);

// ---------------------------------------------------------------- selección y precios
const selected = ref<number[]>([]);
const allSelected = computed(
    () =>
        rows.value.length > 0 &&
        rows.value.every((u) => selected.value.includes(u.id)),
);
const toggleAll = () =>
    (selected.value = allSelected.value ? [] : rows.value.map((u) => u.id));
const toggle = (id: number) =>
    (selected.value = selected.value.includes(id)
        ? selected.value.filter((x) => x !== id)
        : [...selected.value, id]);

const pricesOpen = ref(false);
const prices = useForm({ ids: [] as number[], mode: 'percent', value: 0 });
const applyPrices = () =>
    prices
        .transform((d) => ({ ...d, ids: selected.value }))
        .post(`/projects/${props.project?.id}/units/prices`, {
            preserveScroll: true,
            onSuccess: () => {
                pricesOpen.value = false;
                selected.value = [];
            },
        });

// ---------------------------------------------------------------- unidad, planta, importación
const editing = ref<UnitRow | null>(null);
const unitOpen = ref(false);
const openUnit = (u: UnitRow | null) => {
    editing.value = u;
    unitOpen.value = true;
};
const planUrl = ref<string | null>(null);
const toDelete = ref<UnitRow | null>(null);
const setStatus = (u: UnitRow, status: string) =>
    router.put(`/units/${u.id}/status`, { status }, { preserveScroll: true });

const importOpen = ref(false);
const imp = useForm({ file: null as File | null, update_prices: true });
const doImport = () =>
    imp
        .transform((d) => ({ ...d, update_prices: d.update_prices ? 1 : 0 }))
        .post(`/projects/${props.project?.id}/units/import`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => (importOpen.value = false),
        });
</script>

<template>
    <Head title="Inventario" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Inventario de unidades"
            description="Departamentos, casas, estacionamientos, bodegas y quinchos con su precio en UF, planta y estado."
        >
            <template #actions>
                <template v-if="can.manage && project">
                    <Button variant="outline" as-child
                        ><a href="/units/template.csv"
                            ><Download /> Plantilla CSV</a
                        ></Button
                    >
                    <Button variant="outline" @click="importOpen = true"
                        ><Upload /> Importar</Button
                    >
                    <Button @click="openUnit(null)"
                        ><Plus /> Nueva unidad</Button
                    >
                </template>
            </template>
        </PageHeader>

        <div
            v-if="!projects.length"
            class="rounded-2xl border border-dashed bg-card p-10 text-center text-sm text-muted-foreground"
        >
            No tienes proyectos asignados.
            <Link href="/projects" class="font-medium text-primary underline"
                >Ver proyectos</Link
            >
        </div>

        <template v-else>
            <div
                class="flex flex-wrap items-end gap-3 rounded-2xl border bg-card p-4"
            >
                <FormField
                    label="Proyecto"
                    for="f-proj"
                    class="min-w-56 flex-1 sm:flex-none"
                    ><NativeSelect
                        id="f-proj"
                        :model-value="String(project?.id ?? '')"
                        @update:model-value="(v) => changeProject(String(v))"
                        ><option
                            v-for="p in projects"
                            :key="p.id"
                            :value="String(p.id)"
                        >
                            {{ p.name }}
                        </option></NativeSelect
                    ></FormField
                >
                <FormField label="Tipo" for="f-type"
                    ><NativeSelect id="f-type" v-model="f.type"
                        ><option value="">Todos</option>
                        <option v-for="(l, k) in types" :key="k" :value="k">
                            {{ l }}
                        </option></NativeSelect
                    ></FormField
                >
                <FormField label="Estado" for="f-status"
                    ><NativeSelect id="f-status" v-model="f.status"
                        ><option value="">Todos</option>
                        <option v-for="(l, k) in statuses" :key="k" :value="k">
                            {{ l }} ({{ counts[k] }})
                        </option></NativeSelect
                    ></FormField
                >
                <FormField
                    v-if="bedroomOptions.length"
                    label="Dormitorios"
                    for="f-bed"
                    ><NativeSelect id="f-bed" v-model="f.bedrooms"
                        ><option value="">Todos</option>
                        <option
                            v-for="b in bedroomOptions"
                            :key="String(b)"
                            :value="String(b)"
                        >
                            {{ b }}D
                        </option></NativeSelect
                    ></FormField
                >
                <FormField label="Buscar" for="f-q" class="min-w-48 flex-1"
                    ><div class="relative">
                        <Search
                            class="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                        /><Input
                            id="f-q"
                            v-model="f.q"
                            class="pl-8"
                            placeholder="Número, modelo, torre…"
                        /></div
                ></FormField>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="(l, k) in statuses"
                    :key="k"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition"
                    :class="
                        f.status === k
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'bg-card hover:bg-muted'
                    "
                    @click="f.status = f.status === k ? '' : String(k)"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ backgroundColor: statusColors[k] }"
                    />{{ l }} · {{ counts[k] }}
                </button>
                <span class="ml-auto self-center text-xs text-muted-foreground"
                    >{{ rows.length }} de {{ units.length }} unidades<template
                        v-if="uf"
                    >
                        · UF {{ clp(uf.value) }}</template
                    ></span
                >
            </div>

            <div
                v-if="selected.length && can.manage"
                class="flex flex-wrap items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 px-4 py-2 text-sm"
            >
                <strong>{{ selected.length }} seleccionadas</strong>
                <Button size="sm" variant="outline" @click="pricesOpen = true"
                    ><Tags /> Ajustar precios</Button
                >
                <Button size="sm" variant="ghost" @click="selected = []"
                    >Quitar selección</Button
                >
            </div>

            <div class="overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full min-w-[900px] text-sm">
                    <thead
                        class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th v-if="can.manage" class="w-10 px-3 py-2.5">
                                <input
                                    type="checkbox"
                                    class="size-4 accent-[var(--primary)]"
                                    :checked="allSelected"
                                    @change="toggleAll"
                                />
                            </th>
                            <th class="px-3 py-2.5">Unidad</th>
                            <th class="px-3 py-2.5">Modelo</th>
                            <th class="px-3 py-2.5">Piso</th>
                            <th class="px-3 py-2.5">Programa</th>
                            <th class="px-3 py-2.5 text-right">Útil</th>
                            <th class="px-3 py-2.5 text-right">Total</th>
                            <th class="px-3 py-2.5 text-right">Precio</th>
                            <th class="px-3 py-2.5">Estado</th>
                            <th class="px-3 py-2.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="u in rows"
                            :key="u.id"
                            class="hover:bg-muted/30"
                        >
                            <td v-if="can.manage" class="px-3 py-2">
                                <input
                                    type="checkbox"
                                    class="size-4 accent-[var(--primary)]"
                                    :checked="selected.includes(u.id)"
                                    @change="toggle(u.id)"
                                />
                            </td>
                            <td class="px-3 py-2">
                                <span class="font-semibold">{{ u.code }}</span
                                ><span
                                    class="block text-xs text-muted-foreground"
                                    >{{ types[u.type]
                                    }}{{
                                        u.tower ? ` · Torre ${u.tower}` : ''
                                    }}</span
                                >
                            </td>
                            <td class="px-3 py-2">{{ u.model ?? '—' }}</td>
                            <td class="px-3 py-2">{{ u.floor ?? '—' }}</td>
                            <td class="px-3 py-2">
                                {{ u.program_label ?? '—'
                                }}<span
                                    v-if="u.orientation"
                                    class="block text-xs text-muted-foreground"
                                    >{{ u.orientation }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ m2(u.surface_useful) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ m2(u.surface_total || u.surface_lot) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <span class="font-semibold tabular-nums">{{
                                    fmtUf(u.list_price)
                                }}</span
                                ><span
                                    v-if="uf"
                                    class="block text-xs text-muted-foreground tabular-nums"
                                    >{{
                                        clp(toClp(u.list_price, uf.value))
                                    }}</span
                                >
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white"
                                    :style="{
                                        backgroundColor: statusColors[u.status],
                                    }"
                                    >{{ statuses[u.status] }}</span
                                >
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-0.5">
                                    <Button
                                        v-if="u.floor_plan"
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Ver planta"
                                        @click="planUrl = u.floor_plan"
                                        ><Eye
                                    /></Button>
                                    <Button
                                        v-if="
                                            can.quote &&
                                            u.status === 'disponible'
                                        "
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Cotizar"
                                        as-child
                                        ><Link
                                            :href="`/quotes/create?unit=${u.id}`"
                                            ><Calculator /></Link
                                    ></Button>
                                    <Button
                                        v-if="
                                            can.status &&
                                            u.status === 'disponible'
                                        "
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Bloquear"
                                        @click="setStatus(u, 'bloqueada')"
                                        ><Lock
                                    /></Button>
                                    <Button
                                        v-if="
                                            can.status &&
                                            u.status === 'bloqueada'
                                        "
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Liberar"
                                        @click="setStatus(u, 'disponible')"
                                        ><LockOpen
                                    /></Button>
                                    <Button
                                        v-if="can.manage"
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Editar"
                                        @click="openUnit(u)"
                                        ><Pencil
                                    /></Button>
                                    <Button
                                        v-if="
                                            can.manage &&
                                            [
                                                'disponible',
                                                'bloqueada',
                                            ].includes(u.status)
                                        "
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-destructive hover:text-destructive"
                                        title="Eliminar"
                                        @click="toDelete = u"
                                        ><Trash2
                                    /></Button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td
                                :colspan="can.manage ? 10 : 9"
                                class="px-3 py-10 text-center text-muted-foreground"
                            >
                                {{
                                    units.length
                                        ? 'Ninguna unidad coincide con los filtros.'
                                        : 'Este proyecto aún no tiene unidades: agrégalas una a una o importa el inventario desde un CSV.'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <UnitDialog
        v-if="project"
        v-model:open="unitOpen"
        :unit="editing"
        :project-id="project.id"
        :types="types"
        :orientations="orientations"
        :models="models"
        :uf="uf"
    />

    <Dialog
        :open="!!planUrl"
        @update:open="(v: boolean) => !v && (planUrl = null)"
    >
        <DialogContent class="sm:max-w-4xl"
            ><DialogHeader><DialogTitle>Planta</DialogTitle></DialogHeader
            ><img
                v-if="planUrl"
                :src="planUrl"
                alt="Planta"
                class="max-h-[75dvh] w-full rounded-lg border object-contain"
        /></DialogContent>
    </Dialog>

    <Dialog v-model:open="importOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader
                ><DialogTitle>Importar inventario</DialogTitle
                ><DialogDescription
                    >En Excel: Archivo → Guardar como → CSV. Se crean o
                    actualizan las unidades por tipo + número. Las reservadas o
                    vendidas no cambian de estado.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="doImport">
                <FormField
                    label="Archivo CSV"
                    for="imp-file"
                    :error="imp.errors.file"
                    ><Input
                        id="imp-file"
                        type="file"
                        accept=".csv,text/csv"
                        @change="
                            (e: Event) =>
                                (imp.file =
                                    (e.target as HTMLInputElement).files?.[0] ??
                                    null)
                        "
                /></FormField>
                <label class="flex items-center justify-between gap-3 text-sm"
                    ><span>Actualizar precios de unidades existentes</span
                    ><Switch
                        :model-value="imp.update_prices"
                        @update:model-value="
                            (v: boolean) => (imp.update_prices = v)
                        "
                /></label>
                <p class="text-xs text-muted-foreground">
                    Columnas: tipo, numero, torre, modelo, piso, orientacion,
                    dormitorios, banos, programa, sup_util, sup_terraza,
                    sup_total, sup_terreno, precio_uf, estado.
                    <a
                        href="/units/template.csv"
                        class="font-medium text-primary underline"
                        >Descargar ejemplo</a
                    >
                </p>
                <DialogFooter
                    ><Button
                        type="submit"
                        :disabled="imp.processing || !imp.file"
                        ><Spinner v-if="imp.processing" /><Upload v-else />
                        Importar</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="pricesOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader
                ><DialogTitle>Ajustar precios</DialogTitle
                ><DialogDescription
                    >Se aplica a las {{ selected.length }} unidades
                    seleccionadas (precio de lista en UF).</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="applyPrices">
                <FormField label="Cómo" for="pr-mode"
                    ><NativeSelect id="pr-mode" v-model="prices.mode"
                        ><option value="percent">Subir o bajar un %</option>
                        <option value="amount">Sumar o restar UF</option>
                        <option value="set">
                            Fijar el mismo precio
                        </option></NativeSelect
                    ></FormField
                >
                <FormField
                    :label="
                        prices.mode === 'percent'
                            ? 'Porcentaje (negativo para bajar)'
                            : 'UF'
                    "
                    for="pr-val"
                    :error="prices.errors.value"
                    ><Input
                        id="pr-val"
                        v-model.number="prices.value"
                        type="number"
                        step="0.01"
                /></FormField>
                <DialogFooter
                    ><Button type="submit" :disabled="prices.processing"
                        ><Spinner v-if="prices.processing" /> Aplicar</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="!!toDelete"
        title="Eliminar unidad"
        :description="
            toDelete
                ? `Se eliminará ${types[toDelete.type]} ${toDelete.code} del inventario.`
                : ''
        "
        confirm-label="Eliminar"
        destructive
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="
            toDelete &&
            router.delete(`/units/${toDelete.id}`, {
                preserveScroll: true,
                onFinish: () => (toDelete = null),
            })
        "
    />
</template>
