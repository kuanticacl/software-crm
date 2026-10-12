<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Search } from '@lucide/vue';
import { reactive } from 'vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { uf as fmtUf } from '@/lib/realEstate';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    status_color: string;
    project: { id: number; name: string; color: string } | null;
    client_name: string | null;
    client_rut: string | null;
    units: string;
    total_uf: number;
    owner: string | null;
    reserved_at: string | null;
    promise_due_date: string | null;
    paid_uf: number;
    overdue_count: number;
};

const props = defineProps<{
    reservations: Paginated<Row>;
    filters: { q: string; status: string; project: string };
    projects: { id: number; name: string }[];
    statuses: Record<string, string>;
    statusColors: Record<string, string>;
    summary: Record<string, { label: string; count: number; uf: number }>;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Reservas', href: '/reservations' }] },
});

const f = reactive({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;
const apply = (debounce = false) => {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                '/reservations',
                { ...f },
                { preserveState: true, preserveScroll: true, replace: true },
            ),
        debounce ? 350 : 0,
    );
};
const toggleStatus = (k: string) => {
    f.status = f.status === k ? '' : k;
    apply();
};
const date = (d: string | null) =>
    d ? new Date(`${d}T12:00:00`).toLocaleDateString('es-CL') : '—';
const today = new Date().toISOString().slice(0, 10);
</script>

<template>
    <Head title="Reservas" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Reservas"
            description="Unidades reservadas, plan de pagos del pie, promesa y escritura. Se crean desde una cotización."
        />

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <button
                v-for="(s, k) in summary"
                :key="k"
                type="button"
                class="rounded-2xl border bg-card p-3 text-left transition hover:border-primary/40"
                :class="
                    f.status === k ? 'border-primary ring-1 ring-primary' : ''
                "
                @click="toggleStatus(String(k))"
            >
                <span
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    ><span
                        class="size-2 rounded-full"
                        :style="{ backgroundColor: statusColors[k] }"
                    />{{ s.label }}</span
                >
                <span class="mt-1 block text-xl font-semibold tabular-nums">{{
                    s.count
                }}</span>
                <span
                    class="block text-xs text-muted-foreground tabular-nums"
                    >{{ fmtUf(s.uf) }}</span
                >
            </button>
        </div>

        <div
            class="flex flex-wrap items-end gap-3 rounded-2xl border bg-card p-4"
        >
            <FormField label="Proyecto" for="r-proj"
                ><NativeSelect
                    id="r-proj"
                    v-model="f.project"
                    @update:model-value="apply()"
                    ><option value="">Todos</option>
                    <option
                        v-for="p in projects"
                        :key="p.id"
                        :value="String(p.id)"
                    >
                        {{ p.name }}
                    </option></NativeSelect
                ></FormField
            >
            <FormField label="Estado" for="r-status"
                ><NativeSelect
                    id="r-status"
                    v-model="f.status"
                    @update:model-value="apply()"
                    ><option value="">Todos</option>
                    <option v-for="(l, k) in statuses" :key="k" :value="k">
                        {{ l }}
                    </option></NativeSelect
                ></FormField
            >
            <FormField label="Buscar" for="r-q" class="min-w-48 flex-1"
                ><div class="relative">
                    <Search
                        class="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    /><Input
                        id="r-q"
                        v-model="f.q"
                        class="pl-8"
                        placeholder="N° de reserva, comprador, RUT o unidad…"
                        @update:model-value="apply(true)"
                    /></div
            ></FormField>
        </div>

        <div class="overflow-x-auto rounded-2xl border bg-card">
            <table class="w-full min-w-[860px] text-sm">
                <thead
                    class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2.5">N°</th>
                        <th class="px-3 py-2.5">Comprador</th>
                        <th class="px-3 py-2.5">Proyecto · unidades</th>
                        <th class="px-3 py-2.5 text-right">Total</th>
                        <th class="px-3 py-2.5">Estado</th>
                        <th class="px-3 py-2.5">Promesa</th>
                        <th class="px-3 py-2.5">Pagos</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="r in reservations.data"
                        :key="r.id"
                        class="cursor-pointer hover:bg-muted/30"
                        @click="router.visit(`/reservations/${r.id}`)"
                    >
                        <td class="px-3 py-2.5">
                            <Link
                                :href="`/reservations/${r.id}`"
                                class="font-semibold text-primary"
                                @click.stop
                                >{{ r.number }}</Link
                            ><span
                                class="block text-xs text-muted-foreground"
                                >{{ date(r.reserved_at) }}</span
                            >
                        </td>
                        <td class="px-3 py-2.5">
                            {{ r.client_name ?? '—'
                            }}<span
                                v-if="r.client_rut"
                                class="block text-xs text-muted-foreground"
                                >{{ r.client_rut }}</span
                            >
                        </td>
                        <td class="px-3 py-2.5">
                            <span
                                v-if="r.project"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                ><span
                                    class="size-2 rounded-full"
                                    :style="{
                                        backgroundColor: r.project.color,
                                    }"
                                />{{ r.project.name }}</span
                            >
                            <span class="block">{{ r.units }}</span>
                        </td>
                        <td
                            class="px-3 py-2.5 text-right font-semibold tabular-nums"
                        >
                            {{ fmtUf(r.total_uf) }}
                        </td>
                        <td class="px-3 py-2.5">
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white"
                                :style="{ backgroundColor: r.status_color }"
                                >{{ r.status_label }}</span
                            >
                        </td>
                        <td
                            class="px-3 py-2.5"
                            :class="
                                r.status === 'reserved' &&
                                r.promise_due_date &&
                                r.promise_due_date < today
                                    ? 'font-medium text-destructive'
                                    : ''
                            "
                        >
                            {{
                                r.status === 'reserved'
                                    ? date(r.promise_due_date)
                                    : '—'
                            }}
                        </td>
                        <td class="px-3 py-2.5">
                            <span class="tabular-nums">{{
                                fmtUf(r.paid_uf)
                            }}</span>
                            <span
                                v-if="r.overdue_count"
                                class="mt-0.5 flex items-center gap-1 text-xs font-medium text-destructive"
                                ><AlertTriangle class="size-3" />{{
                                    r.overdue_count
                                }}
                                vencido{{
                                    r.overdue_count > 1 ? 's' : ''
                                }}</span
                            >
                        </td>
                    </tr>
                    <tr v-if="!reservations.data.length">
                        <td
                            colspan="7"
                            class="px-3 py-10 text-center text-muted-foreground"
                        >
                            No hay reservas{{
                                f.q || f.status || f.project
                                    ? ' con estos filtros'
                                    : ''
                            }}. Para reservar, abre una
                            <Link
                                href="/quotes"
                                class="font-medium text-primary underline"
                                >cotización</Link
                            >
                            y presiona «Reservar».
                        </td>
                    </tr>
                </tbody>
            </table>
            <Pagination :paginator="reservations" />
        </div>
    </div>
</template>
