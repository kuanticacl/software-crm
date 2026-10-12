<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building, CalendarClock, MapPin, Plus, Users } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { ufShort, type ProjectCard } from '@/lib/realEstate';

type Stock = {
    total: number;
    by_status: Record<string, number>;
    available_main: number;
    from_uf: number | null;
};

defineProps<{
    projects: (ProjectCard & { stock: Stock })[];
    statuses: Record<string, string>;
    statusColors: Record<string, string>;
    can: { manage: boolean };
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Proyectos', href: '/projects' }] },
});

const share = (s: Stock, k: string) =>
    s.total ? ((s.by_status[k] ?? 0) / s.total) * 100 : 0;
</script>

<template>
    <Head title="Proyectos" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Proyectos inmobiliarios"
            description="Cada proyecto con su inventario, ejecutivos y condiciones comerciales."
        >
            <template #actions>
                <Button v-if="can.manage" as-child
                    ><Link href="/projects/create"
                        ><Plus /> Nuevo proyecto</Link
                    ></Button
                >
            </template>
        </PageHeader>

        <div
            v-if="!projects.length"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card p-12 text-center"
        >
            <span
                class="flex size-12 items-center justify-center rounded-2xl bg-primary/10 text-primary"
                ><Building class="size-6"
            /></span>
            <p class="font-semibold">
                Aún no hay proyectos{{ can.manage ? '' : ' asignados a ti' }}
            </p>
            <p class="max-w-md text-sm text-muted-foreground">
                {{
                    can.manage
                        ? 'Crea el primer proyecto, carga su inventario (puedes importar un Excel en CSV) y asigna a sus ejecutivos.'
                        : 'Pide a tu jefatura que te asigne a un proyecto.'
                }}
            </p>
            <Button v-if="can.manage" as-child
                ><Link href="/projects/create"
                    ><Plus /> Crear proyecto</Link
                ></Button
            >
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="p in projects"
                :key="p.id"
                :href="`/projects/${p.id}`"
                class="group overflow-hidden rounded-2xl border bg-card shadow-sm transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                :class="{ 'opacity-60': !p.is_active }"
            >
                <div
                    class="relative h-40 bg-gradient-to-br from-primary/80 to-primary"
                    :style="
                        p.cover
                            ? {
                                  backgroundImage: `url('${p.cover}')`,
                                  backgroundSize: 'cover',
                                  backgroundPosition: 'center',
                              }
                            : {}
                    "
                >
                    <div
                        class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"
                    />
                    <span
                        class="absolute top-3 left-3 rounded-full bg-white/90 px-2.5 py-0.5 text-[11px] font-semibold text-foreground"
                        >{{ p.stage_label }}</span
                    >
                    <span
                        v-if="!p.is_active"
                        class="absolute top-3 right-3 rounded-full bg-black/60 px-2.5 py-0.5 text-[11px] font-semibold text-white"
                        >Inactivo</span
                    >
                    <div class="absolute right-4 bottom-3 left-4 text-white">
                        <p class="truncate text-lg leading-tight font-bold">
                            {{ p.name }}
                        </p>
                        <p
                            v-if="p.location"
                            class="flex items-center gap-1 truncate text-xs opacity-90"
                        >
                            <MapPin class="size-3.5" /> {{ p.location }}
                        </p>
                    </div>
                </div>
                <div class="grid gap-3 p-4">
                    <div class="flex items-end justify-between gap-2">
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Unidades disponibles
                            </p>
                            <p class="text-xl font-bold">
                                {{ p.stock.available_main }}
                            </p>
                        </div>
                        <div v-if="p.stock.from_uf" class="text-right">
                            <p class="text-xs text-muted-foreground">Desde</p>
                            <p class="font-bold text-primary">
                                {{ ufShort(p.stock.from_uf) }}
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="p.stock.total"
                        class="flex h-2 overflow-hidden rounded-full bg-muted"
                    >
                        <span
                            v-for="(label, k) in statuses"
                            :key="k"
                            :title="`${label}: ${p.stock.by_status[k] ?? 0}`"
                            :style="{
                                width: `${share(p.stock, k)}%`,
                                backgroundColor: statusColors[k],
                            }"
                        />
                    </div>
                    <p v-else class="text-xs text-muted-foreground">
                        Sin inventario cargado
                    </p>
                    <div
                        class="flex items-center justify-between gap-2 text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1"
                            ><Users class="size-3.5" />
                            {{
                                p.executives.length
                                    ? p.executives
                                          .map((e) => e.name.split(' ')[0])
                                          .join(', ')
                                    : 'Sin ejecutivos'
                            }}</span
                        >
                        <span
                            v-if="p.delivery_note || p.delivery_date"
                            class="flex shrink-0 items-center gap-1"
                            ><CalendarClock class="size-3.5" />
                            {{ p.delivery_note || p.delivery_date }}</span
                        >
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>
