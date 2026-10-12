<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BedDouble,
    Calculator,
    Car,
    Home,
    Package,
    Plus,
    Save,
    Search,
    Trash2,
    User,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatRut } from '@/lib/rut';
import {
    clp,
    dividend,
    m2,
    pct,
    r2,
    toClp,
    uf as fmtUf,
} from '@/lib/realEstate';

type ProjectOpt = {
    id: number;
    name: string;
    color: string;
    reservation_amount: number;
    default_pie_pct: number;
    default_pie_installments: number;
    max_discount_pct: number;
    mortgage_rate: number;
    mortgage_years: number;
    quote_validity_days: number;
};
type U = {
    id: number;
    project_id: number;
    type: string;
    code: string;
    model: string | null;
    floor: number | null;
    tower: string | null;
    program: string | null;
    surface_useful: number | null;
    surface_total: number | null;
    orientation: string | null;
    list_price: number;
    status: string;
    is_main: boolean;
    floor_plan: string | null;
    detail: string;
};
type Item = { unit_id: number; discount_pct: number };
type Initial = {
    project_id: number | null;
    lead_id: number | null;
    client_id: number | null;
    buyer: Record<string, string | null>;
    items: Item[];
    pie_pct: number;
    pie_installments: number;
    reservation_amount: number;
    mortgage_rate: number;
    mortgage_years: number;
    valid_until: string | null;
    notes: string | null;
    internal_notes: string | null;
};

const props = defineProps<{
    quote: { id: number; number: string; status: string } | null;
    initial: Initial;
    projects: ProjectOpt[];
    units: U[];
    lead: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
    } | null;
    clients: {
        id: number;
        name: string;
        tax_id: string | null;
        email: string | null;
        phone: string | null;
    }[];
    uf: { value: number; date: string } | null;
    types: Record<string, string>;
    executives: { id: number; name: string }[];
    can: { discount_any: boolean };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Cotizaciones', href: '/quotes' }] },
});

const i0 = props.initial;
const form = useForm({
    project_id: i0.project_id,
    lead_id: i0.lead_id,
    client_id: i0.client_id as number | null,
    user_id: null as number | null,
    buyer: {
        name: i0.buyer?.name ?? '',
        rut: i0.buyer?.rut ?? '',
        email: i0.buyer?.email ?? '',
        phone: i0.buyer?.phone ?? '',
    },
    items: i0.items.map((i) => ({
        unit_id: i.unit_id,
        discount_pct: Number(i.discount_pct ?? 0),
    })) as Item[],
    pie_pct: Number(i0.pie_pct),
    pie_installments: Number(i0.pie_installments),
    reservation_amount: Number(i0.reservation_amount),
    mortgage_rate: Number(i0.mortgage_rate),
    mortgage_years: Number(i0.mortgage_years),
    valid_until: i0.valid_until ?? '',
    notes: i0.notes ?? '',
    internal_notes: i0.internal_notes ?? '',
});

const project = computed(
    () => props.projects.find((p) => p.id === form.project_id) ?? null,
);
const byId = computed(() => new Map(props.units.map((u) => [u.id, u])));
const projectUnits = computed(() =>
    props.units.filter((u) => u.project_id === form.project_id),
);
const chosen = computed(() =>
    form.items
        .map((i) => ({ ...i, unit: byId.value.get(i.unit_id) }))
        .filter((i) => i.unit),
);

// Al cambiar de proyecto se limpian las unidades y se toman sus condiciones por defecto.
watch(
    () => form.project_id,
    (id, old) => {
        if (old === undefined || id === old) return;
        const p = props.projects.find((x) => x.id === id);
        form.items = [];
        if (p) {
            form.pie_pct = Number(p.default_pie_pct);
            form.pie_installments = Number(p.default_pie_installments);
            form.reservation_amount = Number(p.reservation_amount);
            form.mortgage_rate = Number(p.mortgage_rate);
            form.mortgage_years = Number(p.mortgage_years);
            form.valid_until = new Date(
                Date.now() + p.quote_validity_days * 86400000,
            )
                .toISOString()
                .slice(0, 10);
        }
    },
);

// ---------------------------------------------------------------- selector de unidades
const MAIN = ['departamento', 'casa', 'oficina', 'local'];
const search = ref('');
const bedroomsFilter = ref('');
const mainOptions = computed(() => {
    const q = search.value.trim().toLowerCase();
    return projectUnits.value
        .filter((u) => u.is_main && !form.items.some((i) => i.unit_id === u.id))
        .filter(
            (u) =>
                !q ||
                [u.code, u.model, u.program, u.tower].some(
                    (v) => v && String(v).toLowerCase().includes(q),
                ),
        )
        .filter(
            (u) =>
                !bedroomsFilter.value ||
                (u.program ?? '').startsWith(bedroomsFilter.value),
        );
});
const extraTypes = computed(() => [
    ...new Set(
        projectUnits.value
            .filter((u) => !MAIN.includes(u.type))
            .map((u) => u.type),
    ),
]);
const extraPick = ref<Record<string, string>>({});
const extraOptions = (type: string) =>
    projectUnits.value.filter(
        (u) => u.type === type && !form.items.some((i) => i.unit_id === u.id),
    );
const add = (id: number) => {
    if (!form.items.some((i) => i.unit_id === id))
        form.items.push({ unit_id: id, discount_pct: 0 });
};
const addExtra = (type: string) => {
    const id = Number(extraPick.value[type]);
    if (id) add(id);
    extraPick.value[type] = '';
};
const remove = (id: number) =>
    (form.items = form.items.filter((i) => i.unit_id !== id));
const maxDiscount = computed(() =>
    props.can.discount_any ? 100 : Number(project.value?.max_discount_pct ?? 0),
);
const icon = (type: string) =>
    type === 'estacionamiento' ? Car : MAIN.includes(type) ? Home : Package;

// ---------------------------------------------------------------- cálculos (el servidor recalcula al guardar)
const totals = computed(() => {
    const prices = chosen.value.map((i) =>
        r2(
            i.unit!.list_price *
                (1 - Math.min(100, Math.max(0, i.discount_pct || 0)) / 100),
        ),
    );
    const subtotal = chosen.value.reduce((s, i) => s + i.unit!.list_price, 0);
    const total = r2(prices.reduce((s, p) => s + p, 0));
    const pie = r2(
        (total * Math.min(100, Math.max(0, form.pie_pct || 0))) / 100,
    );
    const credit = r2(total - pie);
    const reservaUf = props.uf
        ? r2(form.reservation_amount / props.uf.value)
        : 0;
    const rest = Math.max(0, r2(pie - reservaUf));
    return {
        prices,
        subtotal: r2(subtotal),
        discount: r2(subtotal - total),
        total,
        pie,
        credit,
        reservaUf,
        rest,
        cuota:
            form.pie_installments > 0 ? r2(rest / form.pie_installments) : rest,
        dividend: dividend(
            credit,
            form.mortgage_rate || 0,
            form.mortgage_years || 1,
        ),
    };
});
const inClp = (v: number) => (props.uf ? clp(toClp(v, props.uf.value)) : '');

// ---------------------------------------------------------------- cliente
const pickClient = (id: string) => {
    const c = props.clients.find((x) => x.id === Number(id));
    form.client_id = c?.id ?? null;
    if (c)
        form.buyer = {
            name: c.name,
            rut: c.tax_id ?? '',
            email: c.email ?? '',
            phone: c.phone ?? '',
        };
};

const save = () =>
    props.quote
        ? form.put(`/quotes/${props.quote.id}`, { preserveScroll: true })
        : form.post('/quotes', { preserveScroll: true });
const itemError = (n: number) =>
    (form.errors as Record<string, string>)[`items.${n}.unit_id`] ??
    (form.errors as Record<string, string>)[`items.${n}.discount_pct`];
</script>

<template>
    <Head :title="quote ? `Editar ${quote.number}` : 'Nueva cotización'" />

    <div class="flex min-h-0 flex-1 flex-col">
        <div
            class="sticky top-0 z-10 flex flex-wrap items-center gap-2 border-b bg-card/95 px-3 py-2 backdrop-blur"
        >
            <Button variant="ghost" size="icon-sm" as-child title="Volver"
                ><Link :href="quote ? `/quotes/${quote.id}` : '/quotes'"
                    ><ArrowLeft /></Link
            ></Button>
            <span class="font-semibold">{{
                quote ? `Editar cotización ${quote.number}` : 'Nueva cotización'
            }}</span>
            <div class="ml-auto flex items-center gap-3">
                <span class="hidden text-sm text-muted-foreground sm:inline"
                    >Total
                    <strong class="text-foreground">{{
                        fmtUf(totals.total)
                    }}</strong></span
                >
                <Button
                    size="sm"
                    :disabled="
                        form.processing ||
                        !form.items.length ||
                        !form.project_id
                    "
                    @click="save"
                    ><Spinner v-if="form.processing" /><Save v-else />
                    Guardar</Button
                >
            </div>
        </div>
        <p
            v-if="Object.keys(form.errors).length"
            class="border-b bg-destructive/10 px-4 py-1.5 text-xs text-destructive"
        >
            {{ Object.values(form.errors)[0] }}
        </p>

        <div class="grid gap-5 p-4 md:p-6 xl:grid-cols-[1fr_400px]">
            <div class="grid content-start gap-5">
                <section
                    class="grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-2"
                >
                    <FormField
                        label="Proyecto"
                        for="q-proj"
                        :error="form.errors.project_id"
                        required
                        ><NativeSelect id="q-proj" v-model="form.project_id"
                            ><option
                                v-for="p in projects"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name }}
                            </option></NativeSelect
                        ></FormField
                    >
                    <FormField
                        v-if="executives.length"
                        label="Ejecutivo/a"
                        for="q-exec"
                        ><NativeSelect id="q-exec" v-model="form.user_id"
                            ><option :value="null">Yo</option>
                            <option
                                v-for="e in executives"
                                :key="e.id"
                                :value="e.id"
                            >
                                {{ e.name }}
                            </option></NativeSelect
                        ></FormField
                    >
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 flex items-center gap-2 font-semibold">
                        <User class="size-4 text-primary" /> Cliente
                    </h2>
                    <div
                        v-if="lead"
                        class="mb-4 flex items-center justify-between gap-2 rounded-xl bg-primary/5 px-3 py-2 text-sm"
                    >
                        <span
                            >Prospecto: <strong>{{ lead.name }}</strong></span
                        >
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-destructive"
                            title="Quitar prospecto"
                            @click="form.lead_id = null"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <FormField
                            label="Cliente existente (opcional)"
                            for="q-client"
                            class="sm:col-span-2"
                            ><NativeSelect
                                id="q-client"
                                :model-value="String(form.client_id ?? '')"
                                @update:model-value="
                                    (v) => pickClient(String(v))
                                "
                                ><option value="">
                                    — Datos del cotizante —
                                </option>
                                <option
                                    v-for="c in clients"
                                    :key="c.id"
                                    :value="String(c.id)"
                                >
                                    {{ c.name
                                    }}{{ c.tax_id ? ` · ${c.tax_id}` : '' }}
                                </option></NativeSelect
                            ></FormField
                        >
                        <FormField
                            label="Nombre"
                            for="q-bname"
                            :error="
                                (form.errors as Record<string, string>)[
                                    'buyer.name'
                                ]
                            "
                            ><Input id="q-bname" v-model="form.buyer.name"
                        /></FormField>
                        <FormField
                            label="RUT"
                            for="q-brut"
                            :error="
                                (form.errors as Record<string, string>)[
                                    'buyer.rut'
                                ]
                            "
                            ><Input
                                id="q-brut"
                                v-model="form.buyer.rut"
                                @blur="
                                    form.buyer.rut = formatRut(form.buyer.rut)
                                "
                        /></FormField>
                        <FormField
                            label="Correo"
                            for="q-bmail"
                            :error="
                                (form.errors as Record<string, string>)[
                                    'buyer.email'
                                ]
                            "
                            ><Input
                                id="q-bmail"
                                v-model="form.buyer.email"
                                type="email"
                        /></FormField>
                        <FormField label="Teléfono" for="q-bphone"
                            ><Input
                                id="q-bphone"
                                v-model="form.buyer.phone"
                                placeholder="+56 9 …"
                        /></FormField>
                    </div>
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <Home class="size-4 text-primary" /> Unidades cotizadas
                    </h2>
                    <div
                        v-if="!chosen.length"
                        class="mb-4 rounded-xl border border-dashed p-5 text-center text-sm text-muted-foreground"
                    >
                        Elige la unidad principal abajo y suma estacionamientos,
                        bodegas o quinchos.
                    </div>
                    <div class="grid gap-2">
                        <div
                            v-for="(i, n) in chosen"
                            :key="i.unit_id"
                            class="grid gap-3 rounded-xl border p-3 sm:grid-cols-[56px_1fr_auto]"
                        >
                            <div
                                class="flex size-14 items-center justify-center overflow-hidden rounded-lg border bg-muted/40"
                            >
                                <img
                                    v-if="i.unit!.floor_plan"
                                    :src="i.unit!.floor_plan"
                                    alt=""
                                    class="size-full object-contain"
                                />
                                <component
                                    :is="icon(i.unit!.type)"
                                    v-else
                                    class="size-6 text-muted-foreground"
                                />
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold">
                                    {{ types[i.unit!.type] }} {{ i.unit!.code }}
                                    <span
                                        v-if="i.unit!.status !== 'disponible'"
                                        class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800"
                                        >{{ i.unit!.status }}</span
                                    >
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ i.unit!.detail || '—' }}
                                </p>
                                <p
                                    v-if="itemError(n)"
                                    class="text-xs text-destructive"
                                >
                                    {{ itemError(n) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="w-24">
                                    <label
                                        class="text-[11px] text-muted-foreground"
                                        >Dcto. %</label
                                    ><Input
                                        v-model.number="
                                            form.items[n].discount_pct
                                        "
                                        type="number"
                                        min="0"
                                        :max="maxDiscount"
                                        step="0.5"
                                        class="h-8"
                                    />
                                </div>
                                <div class="text-right">
                                    <p
                                        class="text-[11px] text-muted-foreground line-through"
                                        :class="{
                                            invisible:
                                                !form.items[n].discount_pct,
                                        }"
                                    >
                                        {{ fmtUf(i.unit!.list_price) }}
                                    </p>
                                    <p class="font-bold tabular-nums">
                                        {{
                                            totals.prices[n] > 0
                                                ? fmtUf(totals.prices[n])
                                                : 'Incluido'
                                        }}
                                    </p>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:text-destructive"
                                    title="Quitar"
                                    @click="remove(i.unit_id)"
                                    ><Trash2
                                /></Button>
                            </div>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Descuento máximo autorizado en este proyecto:
                        {{ pct(maxDiscount) }}. Usa 100% para incluir un
                        complemento de regalo.
                    </p>

                    <div
                        v-if="extraTypes.length"
                        class="mt-4 grid gap-3 sm:grid-cols-3"
                    >
                        <div
                            v-for="t in extraTypes"
                            :key="t"
                            class="flex items-end gap-2"
                        >
                            <FormField
                                :label="`Agregar ${types[t].toLowerCase()}`"
                                :for="`x-${t}`"
                                class="flex-1"
                            >
                                <NativeSelect
                                    :id="`x-${t}`"
                                    v-model="extraPick[t]"
                                    ><option value="">
                                        {{
                                            extraOptions(t).length
                                                ? 'Elegir…'
                                                : 'Sin disponibles'
                                        }}
                                    </option>
                                    <option
                                        v-for="u in extraOptions(t)"
                                        :key="u.id"
                                        :value="String(u.id)"
                                    >
                                        {{ u.code }} · {{ fmtUf(u.list_price) }}
                                    </option></NativeSelect
                                >
                            </FormField>
                            <Button
                                variant="outline"
                                size="icon"
                                :disabled="!extraPick[t]"
                                title="Agregar"
                                @click="addExtra(t)"
                                ><Plus
                            /></Button>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <div
                        class="mb-3 flex flex-wrap items-center justify-between gap-3"
                    >
                        <h2 class="flex items-center gap-2 font-semibold">
                            <BedDouble class="size-4 text-primary" /> Unidades
                            disponibles · {{ project?.name }}
                        </h2>
                        <div class="flex gap-2">
                            <NativeSelect
                                v-model="bedroomsFilter"
                                class="h-9 w-28"
                                ><option value="">Todas</option>
                                <option value="1D">1D</option>
                                <option value="2D">2D</option>
                                <option value="3D">3D</option>
                                <option value="4D">4D</option></NativeSelect
                            >
                            <div class="relative">
                                <Search
                                    class="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                                /><Input
                                    v-model="search"
                                    class="h-9 w-44 pl-8"
                                    placeholder="Número o modelo"
                                />
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="!mainOptions.length"
                        class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                    >
                        No hay unidades disponibles con esos filtros.
                    </div>
                    <div
                        class="grid max-h-[28rem] gap-2 overflow-y-auto pr-1 sm:grid-cols-2"
                    >
                        <button
                            v-for="u in mainOptions"
                            :key="u.id"
                            type="button"
                            class="flex items-center gap-3 rounded-xl border p-3 text-left transition hover:border-primary/50 hover:bg-primary/5"
                            @click="add(u.id)"
                        >
                            <div
                                class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/40"
                            >
                                <img
                                    v-if="u.floor_plan"
                                    :src="u.floor_plan"
                                    alt=""
                                    class="size-full object-contain"
                                /><Home
                                    v-else
                                    class="size-5 text-muted-foreground"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">
                                    {{ u.code
                                    }}<span
                                        v-if="u.program"
                                        class="ml-1.5 text-xs font-medium text-primary"
                                        >{{ u.program }}</span
                                    >
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{
                                        [
                                            u.model ? `Mod. ${u.model}` : null,
                                            u.floor !== null
                                                ? `Piso ${u.floor}`
                                                : null,
                                            u.surface_useful
                                                ? m2(u.surface_useful)
                                                : null,
                                            u.orientation,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-right text-sm font-bold tabular-nums"
                                >{{ fmtUf(u.list_price) }}</span
                            >
                        </button>
                    </div>
                </section>
            </div>

            <aside
                class="grid content-start gap-4 xl:sticky xl:top-16 xl:self-start"
            >
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <Calculator class="size-4 text-primary" /> Resumen
                    </h2>
                    <dl class="grid gap-1.5 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Precio lista</dt>
                            <dd class="tabular-nums">
                                {{ fmtUf(totals.subtotal) }}
                            </dd>
                        </div>
                        <div
                            v-if="totals.discount"
                            class="flex justify-between text-emerald-600"
                        >
                            <dt>Descuentos</dt>
                            <dd class="tabular-nums">
                                − {{ fmtUf(totals.discount) }}
                            </dd>
                        </div>
                        <div
                            class="flex items-baseline justify-between rounded-xl bg-primary px-3 py-2 text-primary-foreground"
                        >
                            <dt class="font-semibold">Total</dt>
                            <dd class="text-right">
                                <span class="text-lg font-bold tabular-nums">{{
                                    fmtUf(totals.total)
                                }}</span
                                ><span
                                    v-if="uf"
                                    class="block text-xs opacity-85"
                                    >{{ inClp(totals.total) }}</span
                                >
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <FormField
                            label="Pie %"
                            for="q-pie"
                            :error="form.errors.pie_pct"
                            ><Input
                                id="q-pie"
                                v-model.number="form.pie_pct"
                                type="number"
                                min="0"
                                max="100"
                                step="0.5"
                        /></FormField>
                        <FormField
                            label="Cuotas del pie"
                            for="q-cuo"
                            :error="form.errors.pie_installments"
                            ><Input
                                id="q-cuo"
                                v-model.number="form.pie_installments"
                                type="number"
                                min="0"
                                max="120"
                        /></FormField>
                        <FormField
                            label="Reserva ($)"
                            for="q-res"
                            :error="form.errors.reservation_amount"
                            class="col-span-2"
                            ><Input
                                id="q-res"
                                v-model.number="form.reservation_amount"
                                type="number"
                                min="0"
                                step="10000"
                        /></FormField>
                        <FormField
                            label="Tasa %"
                            for="q-rate"
                            :error="form.errors.mortgage_rate"
                            ><Input
                                id="q-rate"
                                v-model.number="form.mortgage_rate"
                                type="number"
                                min="0"
                                max="30"
                                step="0.01"
                        /></FormField>
                        <FormField
                            label="Años crédito"
                            for="q-yrs"
                            :error="form.errors.mortgage_years"
                            ><Input
                                id="q-yrs"
                                v-model.number="form.mortgage_years"
                                type="number"
                                min="1"
                                max="40"
                        /></FormField>
                    </div>

                    <dl
                        class="mt-4 grid gap-1.5 rounded-xl bg-muted/40 p-3 text-sm"
                    >
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                Pie ({{ pct(form.pie_pct) }})
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ fmtUf(totals.pie) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Reserva</dt>
                            <dd class="tabular-nums">
                                {{ clp(form.reservation_amount) }}
                                <span class="text-xs text-muted-foreground"
                                    >({{ fmtUf(totals.reservaUf) }})</span
                                >
                            </dd>
                        </div>
                        <div class="flex justify-between text-primary">
                            <dt>
                                {{
                                    form.pie_installments > 0
                                        ? `${form.pie_installments} cuotas de`
                                        : 'Saldo pie'
                                }}
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ fmtUf(totals.cuota) }}
                            </dd>
                        </div>
                        <div class="flex justify-between border-t pt-1.5">
                            <dt class="text-muted-foreground">
                                Crédito hipotecario
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ fmtUf(totals.credit) }}
                            </dd>
                        </div>
                        <div class="flex justify-between text-primary">
                            <dt>Dividendo estimado</dt>
                            <dd class="text-right font-semibold tabular-nums">
                                {{ fmtUf(totals.dividend)
                                }}<span
                                    v-if="uf"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ inClp(totals.dividend) }} / mes</span
                                >
                            </dd>
                        </div>
                    </dl>
                    <p v-if="uf" class="mt-2 text-xs text-muted-foreground">
                        UF {{ clp(uf.value) }} ({{ uf.date }}). Al enviar la
                        cotización, la UF del día queda fija.
                    </p>
                </section>

                <section class="grid gap-3 rounded-2xl border bg-card p-5">
                    <FormField
                        label="Válida hasta"
                        for="q-valid"
                        :error="form.errors.valid_until"
                        ><Input
                            id="q-valid"
                            v-model="form.valid_until"
                            type="date"
                    /></FormField>
                    <FormField
                        label="Comentarios para el cliente"
                        for="q-notes"
                        :error="form.errors.notes"
                        ><Textarea
                            id="q-notes"
                            v-model="form.notes"
                            rows="3"
                            placeholder="Visible en la cotización"
                    /></FormField>
                    <FormField
                        label="Notas internas"
                        for="q-inotes"
                        :error="form.errors.internal_notes"
                        ><Textarea
                            id="q-inotes"
                            v-model="form.internal_notes"
                            rows="2"
                            placeholder="Solo el equipo"
                    /></FormField>
                </section>
            </aside>
        </div>
    </div>
</template>
