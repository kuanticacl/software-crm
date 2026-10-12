<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import { reactive } from 'vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { clp, uf as fmtUf } from '@/lib/realEstate';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    status_color: string;
    project: { id: number; name: string; color: string } | null;
    buyer_name: string | null;
    units: string;
    total_uf: number;
    total_clp: number | null;
    owner: string | null;
    valid_until: string | null;
    created_at: string | null;
};

const props = defineProps<{
    quotes: Paginated<Row>;
    filters: { q: string; status: string; project: string };
    projects: { id: number; name: string }[];
    statuses: Record<string, string>;
    statusColors: Record<string, string>;
    summary: Record<string, { label: string; count: number; uf: number }>;
    can: { create: boolean };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Cotizaciones', href: '/quotes' }] },
});

const f = reactive({ ...props.filters });
let timer: ReturnType<typeof setTimeout> | undefined;
const apply = (debounce = false) => {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.get(
                '/quotes',
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
    d
        ? new Date(d.length === 10 ? `${d}T12:00:00` : d).toLocaleDateString(
              'es-CL',
          )
        : '—';
</script>

<template>
    <Head title="Cotizaciones" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Cotizaciones"
            description="Unidad + estacionamiento, bodega o quincho, con pie en cuotas y simulación del crédito."
        >
            <template #actions>
                <Button v-if="can.create" as-child
                    ><Link href="/quotes/create"
                        ><Plus /> Nueva cotización</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
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
            <FormField label="Proyecto" for="q-proj"
                ><NativeSelect
                    id="q-proj"
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
            <FormField label="Estado" for="q-status"
                ><NativeSelect
                    id="q-status"
                    v-model="f.status"
                    @update:model-value="apply()"
                    ><option value="">Todos</option>
                    <option v-for="(l, k) in statuses" :key="k" :value="k">
                        {{ l }}
                    </option></NativeSelect
                ></FormField
            >
            <FormField label="Buscar" for="q-q" class="min-w-48 flex-1"
                ><div class="relative">
                    <Search
                        class="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    /><Input
                        id="q-q"
                        v-model="f.q"
                        class="pl-8"
                        placeholder="N° de cotización, comprador o unidad…"
                        @update:model-value="apply(true)"
                    /></div
            ></FormField>
        </div>

        <div class="overflow-x-auto rounded-2xl border bg-card">
            <table class="w-full min-w-[820px] text-sm">
                <thead
                    class="border-b bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2.5">N°</th>
                        <th class="px-3 py-2.5">Comprador</th>
                        <th class="px-3 py-2.5">Proyecto · unidades</th>
                        <th class="px-3 py-2.5 text-right">Total</th>
                        <th class="px-3 py-2.5">Estado</th>
                        <th class="px-3 py-2.5">Vigencia</th>
                        <th class="px-3 py-2.5">Ejecutivo/a</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="q in quotes.data"
                        :key="q.id"
                        class="cursor-pointer hover:bg-muted/30"
                        @click="router.visit(`/quotes/${q.id}`)"
                    >
                        <td class="px-3 py-2.5">
                            <Link
                                :href="`/quotes/${q.id}`"
                                class="font-semibold text-primary"
                                @click.stop
                                >{{ q.number }}</Link
                            ><span
                                class="block text-xs text-muted-foreground"
                                >{{ date(q.created_at) }}</span
                            >
                        </td>
                        <td class="px-3 py-2.5">{{ q.buyer_name ?? '—' }}</td>
                        <td class="px-3 py-2.5">
                            <span
                                v-if="q.project"
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                                ><span
                                    class="size-2 rounded-full"
                                    :style="{
                                        backgroundColor: q.project.color,
                                    }"
                                />{{ q.project.name }}</span
                            >
                            <span class="block">{{ q.units }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <span class="font-semibold tabular-nums">{{
                                fmtUf(q.total_uf)
                            }}</span
                            ><span
                                v-if="q.total_clp"
                                class="block text-xs text-muted-foreground tabular-nums"
                                >{{ clp(q.total_clp) }}</span
                            >
                        </td>
                        <td class="px-3 py-2.5">
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold text-white"
                                :style="{ backgroundColor: q.status_color }"
                                >{{ q.status_label }}</span
                            >
                        </td>
                        <td class="px-3 py-2.5">{{ date(q.valid_until) }}</td>
                        <td class="px-3 py-2.5">{{ q.owner ?? '—' }}</td>
                    </tr>
                    <tr v-if="!quotes.data.length">
                        <td
                            colspan="7"
                            class="px-3 py-10 text-center text-muted-foreground"
                        >
                            No hay cotizaciones{{
                                f.q || f.status || f.project
                                    ? ' con estos filtros'
                                    : ''
                            }}.
                            <Link
                                v-if="can.create && !f.q && !f.status"
                                href="/quotes/create"
                                class="font-medium text-primary underline"
                                >Crear la primera</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
            <Pagination :paginator="quotes" />
        </div>
    </div>
</template>
