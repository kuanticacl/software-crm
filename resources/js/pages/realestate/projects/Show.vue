<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Calculator,
    CalendarClock,
    ExternalLink,
    FileText,
    LayoutGrid,
    MapPin,
    Pencil,
    Store,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    clp,
    pct,
    toClp,
    uf as fmtUf,
    ufShort,
    type ProjectCard,
} from '@/lib/realEstate';

type U = {
    id: number;
    type: string;
    code: string;
    tower: string | null;
    model: string | null;
    floor: number | null;
    program: string | null;
    surface_useful: number | null;
    surface_total: number | null;
    list_price: number;
    status: string;
};
const props = defineProps<{
    project: ProjectCard &
        Record<string, unknown> & {
            reservation_amount: number;
            default_pie_pct: number;
            default_pie_installments: number;
            max_discount_pct: number;
            mortgage_rate: number;
            mortgage_years: number;
            quote_validity_days: number;
            promise_days: number;
            address: string | null;
            sales_office: string | null;
            description: string | null;
            website_url: string | null;
            brochure_url: string | null;
        };
    units: U[];
    kpis: {
        quotes_30d: number;
        reservations_30d: number;
        sold_uf: number;
        reserved_uf: number;
        stock_uf: number;
    };
    uf: { value: number; date: string } | null;
    types: Record<string, string>;
    statuses: Record<string, string>;
    statusColors: Record<string, string>;
    can: { manage: boolean; units: boolean; quote: boolean };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Proyectos', href: '/projects' }] },
});

const MAIN = ['departamento', 'casa', 'oficina', 'local'];
const mains = computed(() => props.units.filter((u) => MAIN.includes(u.type)));
const extras = computed(() =>
    props.units.filter((u) => !MAIN.includes(u.type)),
);

/** Planta de ventas: por torre, pisos de arriba hacia abajo. */
const towers = computed(() => {
    const map = new Map<string, Map<number, U[]>>();
    for (const u of mains.value) {
        const t = u.tower ?? '';
        const f = u.floor ?? 0;
        if (!map.has(t)) map.set(t, new Map());
        const floors = map.get(t)!;
        if (!floors.has(f)) floors.set(f, []);
        floors.get(f)!.push(u);
    }
    return [...map.entries()].map(([tower, floors]) => ({
        tower,
        floors: [...floors.entries()]
            .sort((a, b) => b[0] - a[0])
            .map(([floor, units]) => ({
                floor,
                units: units.sort((a, b) =>
                    a.code.localeCompare(b.code, 'es', { numeric: true }),
                ),
            })),
    }));
});

const count = (list: U[], status?: string) =>
    list.filter((u) => !status || u.status === status).length;
const extraTypes = computed(() => [
    ...new Set(extras.value.map((u) => u.type)),
]);
const totalUnits = computed(() => mains.value.length);
const soldPct = computed(() =>
    totalUnits.value
        ? Math.round(
              ((count(mains.value, 'vendida') +
                  count(mains.value, 'promesada') +
                  count(mains.value, 'reservada')) /
                  totalUnits.value) *
                  100,
          )
        : 0,
);
const cellHref = (u: U) =>
    u.status === 'disponible' && props.can.quote
        ? `/quotes/create?unit=${u.id}`
        : `/inventory?project=${props.project.id}&q=${encodeURIComponent(u.code)}`;
</script>

<template>
    <Head :title="project.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <section
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary to-primary/70 text-white"
            :style="
                project.cover
                    ? {
                          backgroundImage: `url('${project.cover}')`,
                          backgroundSize: 'cover',
                          backgroundPosition: 'center',
                      }
                    : {}
            "
        >
            <div
                class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/30 to-transparent"
            />
            <div
                class="relative flex min-h-56 flex-col justify-between gap-6 p-5 md:p-7"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Button variant="secondary" size="sm" as-child
                        ><Link href="/projects"
                            ><ArrowLeft /> Proyectos</Link
                        ></Button
                    >
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="can.quote" size="sm" as-child
                            ><Link
                                :href="`/quotes/create?project=${project.id}`"
                                ><Calculator /> Cotizar</Link
                            ></Button
                        >
                        <Button variant="secondary" size="sm" as-child
                            ><Link :href="`/inventory?project=${project.id}`"
                                ><LayoutGrid /> Inventario</Link
                            ></Button
                        >
                        <Button
                            v-if="can.manage"
                            variant="secondary"
                            size="sm"
                            as-child
                            ><Link :href="`/projects/${project.id}/edit`"
                                ><Pencil /> Editar</Link
                            ></Button
                        >
                    </div>
                </div>
                <div>
                    <span
                        class="rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-semibold backdrop-blur"
                        >{{ project.stage_label }}</span
                    >
                    <h1 class="mt-2 text-3xl font-bold tracking-tight">
                        {{ project.name }}
                    </h1>
                    <p
                        class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm opacity-90"
                    >
                        <span
                            v-if="project.location"
                            class="flex items-center gap-1"
                            ><MapPin class="size-4" />
                            {{ project.location }}</span
                        >
                        <span v-if="project.developer">{{
                            project.developer
                        }}</span>
                        <span
                            v-if="
                                project.delivery_note || project.delivery_date
                            "
                            class="flex items-center gap-1"
                            ><CalendarClock class="size-4" /> Entrega
                            {{
                                project.delivery_note || project.delivery_date
                            }}</span
                        >
                    </p>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Avance de ventas</p>
                <p class="text-2xl font-bold">{{ soldPct }}%</p>
                <p class="text-xs text-muted-foreground">
                    {{ count(mains, 'disponible') }} de
                    {{ totalUnits }} disponibles
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Stock disponible</p>
                <p class="text-2xl font-bold">{{ ufShort(kpis.stock_uf) }}</p>
                <p v-if="uf" class="text-xs text-muted-foreground">
                    ≈ {{ clp(toClp(kpis.stock_uf, uf.value)) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Reservado</p>
                <p class="text-2xl font-bold text-amber-600">
                    {{ ufShort(kpis.reserved_uf) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Promesado / escriturado
                </p>
                <p class="text-2xl font-bold text-primary">
                    {{ ufShort(kpis.sold_uf) }}
                </p>
            </div>
            <div
                class="col-span-2 rounded-2xl border bg-card p-4 lg:col-span-1"
            >
                <p class="text-xs text-muted-foreground">Últimos 30 días</p>
                <p class="text-2xl font-bold">
                    {{ kpis.quotes_30d }}
                    <span class="text-sm font-medium text-muted-foreground"
                        >cotiz.</span
                    >
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ kpis.reservations_30d }} reservas
                </p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
            <section class="rounded-2xl border bg-card p-5">
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-3"
                >
                    <h2 class="font-semibold">Planta de ventas</h2>
                    <div class="flex flex-wrap gap-3 text-xs">
                        <span
                            v-for="(label, k) in statuses"
                            :key="k"
                            class="flex items-center gap-1.5"
                            ><span
                                class="size-3 rounded"
                                :style="{ backgroundColor: statusColors[k] }"
                            />{{ label }} ({{ count(mains, k) }})</span
                        >
                    </div>
                </div>
                <div
                    v-if="!mains.length"
                    class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    Aún no hay unidades.
                    <Link
                        v-if="can.units"
                        :href="`/inventory?project=${project.id}`"
                        class="font-medium text-primary underline"
                        >Carga el inventario</Link
                    >
                </div>
                <div class="grid gap-6">
                    <div
                        v-for="t in towers"
                        :key="t.tower"
                        class="overflow-x-auto"
                    >
                        <p v-if="t.tower" class="mb-2 text-sm font-semibold">
                            Torre {{ t.tower }}
                        </p>
                        <div class="grid gap-1.5">
                            <div
                                v-for="f in t.floors"
                                :key="f.floor"
                                class="flex items-stretch gap-1.5"
                            >
                                <span
                                    class="flex w-12 shrink-0 items-center justify-end pr-1 text-xs font-medium text-muted-foreground"
                                    >{{
                                        f.floor ? `Piso ${f.floor}` : '—'
                                    }}</span
                                >
                                <Link
                                    v-for="u in f.units"
                                    :key="u.id"
                                    :href="cellHref(u)"
                                    class="flex min-w-16 flex-col justify-center rounded-lg px-2 py-1.5 text-white shadow-sm transition hover:scale-[1.04] hover:shadow-md"
                                    :style="{
                                        backgroundColor: statusColors[u.status],
                                    }"
                                    :title="`${types[u.type]} ${u.code} · ${u.program ?? ''} · ${fmtUf(u.list_price)} · ${statuses[u.status]}`"
                                >
                                    <span
                                        class="text-sm leading-tight font-bold"
                                        >{{ u.code }}</span
                                    >
                                    <span
                                        class="text-[10px] leading-tight opacity-90"
                                        >{{ u.program || u.model || '' }}</span
                                    >
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
                <div
                    v-if="extraTypes.length"
                    class="mt-6 grid gap-2 sm:grid-cols-3"
                >
                    <div
                        v-for="t in extraTypes"
                        :key="t"
                        class="rounded-xl border bg-muted/30 p-3"
                    >
                        <p class="text-sm font-semibold">{{ types[t] }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                count(
                                    extras.filter((u) => u.type === t),
                                    'disponible',
                                )
                            }}
                            disponibles de
                            {{ extras.filter((u) => u.type === t).length }}
                        </p>
                    </div>
                </div>
            </section>

            <div class="grid content-start gap-4">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <Users class="size-4 text-primary" /> Ejecutivos
                    </h2>
                    <ul v-if="project.executives.length" class="grid gap-2">
                        <li
                            v-for="e in project.executives"
                            :key="e.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <span
                                class="flex size-7 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary"
                                >{{ e.name.charAt(0) }}</span
                            >{{ e.name
                            }}<span
                                v-if="e.is_lead"
                                class="text-amber-500"
                                title="Jefatura de ventas"
                                >★</span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        Sin ejecutivos asignados.
                    </p>
                </section>
                <section class="rounded-2xl border bg-card p-5 text-sm">
                    <h2 class="mb-3 font-semibold">Condiciones comerciales</h2>
                    <dl class="grid gap-1.5">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Reserva</dt>
                            <dd class="font-medium">
                                {{ clp(project.reservation_amount) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Pie</dt>
                            <dd class="font-medium">
                                {{ pct(project.default_pie_pct) }} en
                                {{ project.default_pie_installments || 'una' }}
                                cuota{{
                                    project.default_pie_installments === 1
                                        ? ''
                                        : 's'
                                }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                Descuento máx.
                            </dt>
                            <dd class="font-medium">
                                {{ pct(project.max_discount_pct) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Crédito</dt>
                            <dd class="font-medium">
                                {{ project.mortgage_years }} años ·
                                {{ pct(project.mortgage_rate) }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                Vigencia cotización
                            </dt>
                            <dd class="font-medium">
                                {{ project.quote_validity_days }} días
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Plazo promesa</dt>
                            <dd class="font-medium">
                                {{ project.promise_days }} días
                            </dd>
                        </div>
                    </dl>
                </section>
                <section
                    v-if="
                        project.address ||
                        project.sales_office ||
                        project.website_url ||
                        project.brochure_url ||
                        project.description
                    "
                    class="grid gap-2 rounded-2xl border bg-card p-5 text-sm"
                >
                    <p v-if="project.address" class="flex gap-2">
                        <MapPin class="mt-0.5 size-4 shrink-0 text-primary" />
                        {{ project.address }}
                    </p>
                    <p v-if="project.sales_office" class="flex gap-2">
                        <Store class="mt-0.5 size-4 shrink-0 text-primary" />
                        {{ project.sales_office }}
                    </p>
                    <a
                        v-if="project.website_url"
                        :href="project.website_url"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center gap-2 text-primary hover:underline"
                        ><ExternalLink class="size-4" /> Sitio del proyecto</a
                    >
                    <a
                        v-if="project.brochure_url"
                        :href="project.brochure_url"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center gap-2 text-primary hover:underline"
                        ><FileText class="size-4" /> Brochure</a
                    >
                    <p
                        v-if="project.description"
                        class="whitespace-pre-line text-muted-foreground"
                    >
                        {{ project.description }}
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
