<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlarmClock, ArrowRight, CalendarPlus, CircleCheckBig, CircleX, Columns3, Layers, List, Percent, Plus, Sparkles, TrendingUp, Trophy } from '@lucide/vue';
import { computed } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import SourceIcon from '@/components/SourceIcon.vue';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { formatMoneyShort } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as kanban } from '@/routes/kanban';
import { create, show as leadShow } from '@/routes/leads';
import { index as proposalsIndex, show as proposalShow } from '@/routes/proposals';
import { timeAgo } from '@/lib/format';

type Stage = { id: number; name: string; color: string; type: string; total: number; value: number };
type Source = { id: number; name: string; color: string; icon: string; is_active: boolean; count: number; pct: number };
const props = defineProps<{
    filters: { period: string };
    periods: Record<string, string>;
    stats: { total: number; today: number; open: number; won: number; lost: number; conversion: number; overdue: number; pipeline_value: number; won_value: number };
    funnel: Stage[];
    daily: { date: string; leads: number }[];
    sources: Source[];
    followUps: { id: number; name: string; company: string | null; stage: string | null; color: string | null; assignee: string | null; due: string; overdue: boolean }[];
    recent: { id: number; name: string; company: string | null; source: string | null; source_color: string | null; stage: string | null; color: string | null; score_grade: string | null; created_at: string }[];
    proposals: { counts: { key: string; label: string; color: string; count: number }[]; attention: { id: number; number: string; title: string; company: string | null; status: string; status_label: string; color: string; at: string | null }[] } | null;
    can: { create: boolean; leads: boolean; proposals: boolean };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] } });

const setPeriod = (v: string | number | null) => router.get(dashboard().url, { period: v === 'all' ? undefined : v }, { preserveState: true, preserveScroll: true, replace: true });

const kpis = computed(() => [
    { label: 'Prospectos', value: props.stats.total, icon: Layers, color: '#6419DB', hint: props.periods[props.filters.period] },
    { label: 'Nuevos hoy', value: props.stats.today, icon: CalendarPlus, color: '#1AA0E4', hint: 'Ingresados hoy' },
    { label: 'Conversión', value: `${props.stats.conversion}%`, icon: Percent, color: '#DF1E79', hint: `${props.stats.won} concretados de ${props.stats.total}` },
    { label: 'Valor en pipeline', value: formatMoneyShort(props.stats.pipeline_value) || '$0', icon: TrendingUp, color: '#4A8CFF', hint: 'Prospectos en gestión' },
]);
const secondary = computed(() => [
    { label: 'En gestión', value: props.stats.open, icon: Sparkles, color: '#3B2F2F' },
    { label: 'Concretados', value: props.stats.won, icon: CircleCheckBig, color: '#0D9F85' },
    { label: 'Descartados', value: props.stats.lost, icon: CircleX, color: '#8A8A8A' },
    { label: 'Ventas concretadas', value: formatMoneyShort(props.stats.won_value) || '$0', icon: Trophy, color: '#0D9F85' },
    { label: 'Seguimientos vencidos', value: props.stats.overdue, icon: AlarmClock, color: '#DC2626', alert: props.stats.overdue > 0 },
]);

const maxStage = computed(() => Math.max(1, ...props.funnel.map((s) => s.total)));
const dueLabel = (iso: string) => new Intl.DateTimeFormat('es-CL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
const gradeCls: Record<string, string> = { A: 'bg-emerald-100 text-emerald-700', B: 'bg-sky-100 text-sky-700', C: 'bg-amber-100 text-amber-700', D: 'bg-zinc-100 text-zinc-600' };
</script>

<template>
    <Head title="Dashboard" />

    <div class="mx-auto flex w-full max-w-[1500px] min-w-0 flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Dashboard" description="Resumen comercial: cómo va tu pipeline y qué requiere atención hoy.">
            <template #actions>
                <NativeSelect :model-value="filters.period" class="w-44" @update:model-value="setPeriod">
                    <option v-for="(label, key) in periods" :key="key" :value="key">{{ label }}</option>
                </NativeSelect>
                <Button variant="outline" as-child><Link :href="kanban()"><Columns3 /> Abrir Kanban</Link></Button>
                <Button v-if="can.create" as-child><Link :href="create()"><Plus /> Nuevo prospecto</Link></Button>
            </template>
        </PageHeader>

        <!-- 1 · Indicadores -->
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores principales">
            <div v-for="k in kpis" :key="k.label" class="flex items-center gap-4 rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl [&_svg]:size-6" :style="{ backgroundColor: k.color + '1A', color: k.color }"><component :is="k.icon" /></div>
                <div class="min-w-0">
                    <p class="text-3xl leading-none font-bold tabular-nums">{{ k.value }}</p>
                    <p class="mt-1.5 text-sm font-medium">{{ k.label }}</p>
                    <p class="truncate text-xs text-muted-foreground">{{ k.hint }}</p>
                </div>
            </div>
        </section>
        <section class="flex flex-wrap gap-2" aria-label="Más indicadores">
            <Link
                v-for="s in secondary"
                :key="s.label"
                :href="s.alert ? kanban({ query: { overdue: 1 } }) : kanban()"
                :class="cn('flex items-center gap-2 rounded-full border bg-card px-3.5 py-1.5 text-sm shadow-sm shadow-black/[0.02] transition hover:bg-muted', s.alert && 'border-destructive/50 text-destructive')"
            >
                <component :is="s.icon" class="size-4" :style="{ color: s.color }" />
                <span class="font-semibold tabular-nums">{{ s.value }}</span>
                <span class="text-muted-foreground" :class="s.alert && '!text-destructive'">{{ s.label }}</span>
            </Link>
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-6 xl:col-span-2">
                <!-- 2 · Embudo -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                    <div class="mb-4 flex items-baseline justify-between gap-2">
                        <h2 class="font-semibold">Embudo por etapa</h2>
                        <Link :href="kanban()" class="inline-flex items-center gap-1 text-xs text-primary hover:underline">Ver tablero <ArrowRight class="size-3" /></Link>
                    </div>
                    <ul class="flex flex-col gap-2.5">
                        <li v-for="s in funnel" :key="s.id">
                            <Link :href="kanban()" class="group grid grid-cols-[8.5rem_1fr_auto] items-center gap-3 text-sm">
                                <span class="flex items-center gap-2 truncate"><span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: s.color }" /><span class="truncate group-hover:underline">{{ s.name }}</span></span>
                                <span class="h-6 overflow-hidden rounded-md bg-muted"><span class="block h-full min-w-1 rounded-md transition-all" :style="{ width: (s.total / maxStage) * 100 + '%', backgroundColor: s.color }" /></span>
                                <span class="w-24 text-right tabular-nums"><strong>{{ s.total }}</strong><span v-if="s.value" class="ml-1.5 text-xs text-muted-foreground">{{ formatMoneyShort(s.value) }}</span></span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <!-- 3 · Actividad -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                    <h2 class="mb-3 font-semibold">Prospectos nuevos · últimos 14 días</h2>
                    <BarChart :labels="daily.map((d) => d.date)" :series="[{ key: 'leads', label: 'Prospectos', color: 'var(--primary)', values: daily.map((d) => d.leads) }]" :height="150" chart-label="Prospectos nuevos por día" />
                </section>

                <!-- 4 · Orígenes -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                    <div class="mb-4 flex items-baseline justify-between gap-2">
                        <h2 class="font-semibold">Prospectos por origen</h2>
                        <p class="text-xs text-muted-foreground">Clic para verlos en el Kanban</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                        <Link v-for="s in sources" :key="s.id" :href="kanban({ query: { sources: s.id } })" class="flex flex-col gap-2 rounded-xl border p-3 transition hover:bg-muted/60">
                            <div class="flex items-center gap-2"><span class="flex size-7 shrink-0 items-center justify-center rounded-lg text-white [&_svg]:size-4" :style="{ backgroundColor: s.color }"><SourceIcon :name="s.icon" /></span><span class="truncate text-sm font-medium">{{ s.name }}</span></div>
                            <div class="flex items-end justify-between"><span class="text-2xl leading-none font-bold tabular-nums">{{ s.count }}</span><span class="text-xs text-muted-foreground">{{ s.pct }}%</span></div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full" :style="{ width: s.pct + '%', backgroundColor: s.color }" /></div>
                        </Link>
                    </div>
                </section>
            </div>

            <div class="flex min-w-0 flex-col gap-6">
                <!-- 5 · Seguimientos -->
                <section class="rounded-2xl border bg-card shadow-sm shadow-black/[0.03]">
                    <div class="flex items-center justify-between border-b px-5 py-3.5"><h2 class="font-semibold">Seguimientos para hoy</h2><span v-if="followUps.length" class="rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-semibold text-destructive">{{ followUps.length }}</span></div>
                    <p v-if="!followUps.length" class="px-5 py-8 text-center text-sm text-muted-foreground">Sin seguimientos pendientes. ¡Todo al día! 🎉</p>
                    <ul v-else class="divide-y">
                        <li v-for="l in followUps" :key="l.id">
                            <Link :href="leadShow(l.id)" class="flex items-start gap-3 px-5 py-3 transition hover:bg-muted/50">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="l.overdue ? 'bg-destructive' : 'bg-amber-500'" />
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ l.name }}</span><span class="block truncate text-xs text-muted-foreground">{{ l.company || l.stage }}<template v-if="l.assignee"> · {{ l.assignee }}</template></span></span>
                                <span :class="cn('shrink-0 text-xs', l.overdue ? 'font-medium text-destructive' : 'text-muted-foreground')">{{ dueLabel(l.due) }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <!-- 6 · Propuestas -->
                <section v-if="proposals" class="rounded-2xl border bg-card shadow-sm shadow-black/[0.03]">
                    <div class="flex items-center justify-between border-b px-5 py-3.5"><h2 class="font-semibold">Propuestas</h2><Link :href="proposalsIndex()" class="inline-flex items-center gap-1 text-xs text-primary hover:underline">Ver todas <ArrowRight class="size-3" /></Link></div>
                    <div class="flex flex-wrap gap-1.5 px-5 pt-4">
                        <span v-for="c in proposals.counts.filter((c) => c.count)" :key="c.key" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" :style="{ backgroundColor: c.color + '1F', color: c.color }">{{ c.label }} <strong class="tabular-nums">{{ c.count }}</strong></span>
                        <span v-if="!proposals.counts.some((c) => c.count)" class="text-sm text-muted-foreground">Aún no hay propuestas.</span>
                    </div>
                    <ul v-if="proposals.attention.length" class="mt-3 divide-y border-t">
                        <li v-for="p in proposals.attention" :key="p.id">
                            <Link :href="proposalShow(p.id)" class="flex items-start gap-3 px-5 py-3 transition hover:bg-muted/50">
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ p.company || p.title }}</span><span class="block truncate text-xs text-muted-foreground">{{ p.number }} · {{ p.title }}</span></span>
                                <span class="shrink-0 text-right"><span class="block rounded-full px-2 py-0.5 text-[11px] font-medium" :style="{ backgroundColor: p.color + '1F', color: p.color }">{{ p.status_label }}</span><span class="text-[11px] text-muted-foreground">{{ timeAgo(p.at) }}</span></span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <!-- 7 · Últimos leads -->
                <section class="rounded-2xl border bg-card shadow-sm shadow-black/[0.03]">
                    <div class="flex items-center justify-between border-b px-5 py-3.5"><h2 class="font-semibold">Últimos prospectos</h2><Link v-if="can.leads" :href="'/leads'" class="inline-flex items-center gap-1 text-xs text-primary hover:underline"><List class="size-3" /> Ver lista</Link></div>
                    <p v-if="!recent.length" class="px-5 py-8 text-center text-sm text-muted-foreground">Aún no hay prospectos.</p>
                    <ul v-else class="divide-y">
                        <li v-for="l in recent" :key="l.id">
                            <Link :href="leadShow(l.id)" class="flex items-center gap-3 px-5 py-2.5 transition hover:bg-muted/50">
                                <span v-if="l.score_grade" :class="cn('flex size-6 shrink-0 items-center justify-center rounded-md text-xs font-bold', gradeCls[l.score_grade])">{{ l.score_grade }}</span>
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ l.name }}</span><span class="block truncate text-xs text-muted-foreground">{{ l.source }}<template v-if="l.company"> · {{ l.company }}</template></span></span>
                                <span class="shrink-0 text-right"><span class="flex items-center justify-end gap-1.5 text-xs"><span class="size-2 rounded-full" :style="{ backgroundColor: l.color ?? '#999' }" />{{ l.stage }}</span><span class="text-[11px] text-muted-foreground">{{ timeAgo(l.created_at) }}</span></span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>
