<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import {
    LayoutDashboard,
    List,
    Plus,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import KanbanBoard from '@/components/kanban/KanbanBoard.vue';
import KanbanToolbar from '@/components/kanban/KanbanToolbar.vue';
import type { Filters } from '@/components/kanban/KanbanToolbar.vue';
import { Button } from '@/components/ui/button';
import { formatMoneyShort } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { index as kanbanIndex } from '@/routes/kanban';
import { dashboard } from '@/routes';
import { create, index as leadsIndex } from '@/routes/leads';
import type { BoardColumn, StageRef, UserOption } from '@/types';

type SourceTile = { id: number; name: string; color: string; icon: string; is_active: boolean; count: number; pct: number };
type RawFilters = { period: string; sort: string; q: string | null; sources: string[]; assignees: string[]; priorities: string[]; grades: string[]; tag: string | null; overdue: boolean; no_followup: boolean };

const props = defineProps<{
    filters: RawFilters;
    periods: Record<string, string>;
    sorts: Record<string, string>;
    priorities: Record<string, string>;
    stats: { total: number; today: number; open: number; won: number; lost: number; conversion: number; overdue: number; pipeline_value: number; won_value: number };
    sources: SourceTile[];
    board: BoardColumn[];
    columnPage: number;
    users: UserOption[];
    tags: string[];
    stages: (StageRef & { type: string })[];
    manualSourceId: number | null;
    can: { create: boolean; move: boolean; assign: boolean; update: boolean; delete: boolean; viewAll: boolean; email: boolean };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Kanban', href: kanbanIndex() }] } });

const page = usePage();

const toLocal = (r: RawFilters): Filters => ({
    q: r.q ?? '', period: r.period, sort: r.sort, sources: [...r.sources], assignees: [...r.assignees],
    priorities: [...r.priorities], grades: [...(r.grades ?? [])], tag: r.tag ?? '', overdue: r.overdue, no_followup: r.no_followup,
});
const f = ref<Filters>(toLocal(props.filters));

const query = computed<Record<string, string>>(() => {
    const v = f.value;
    const q: Record<string, string> = {};
    if (v.period !== 'all') q.period = v.period;
    if (v.sort !== 'manual') q.sort = v.sort;
    if (v.q.trim()) q.q = v.q.trim();
    if (v.sources.length) q.sources = v.sources.join(',');
    if (v.assignees.length) q.assignees = v.assignees.join(',');
    if (v.priorities.length) q.priorities = v.priorities.join(',');
    if (v.grades.length) q.grades = v.grades.join(',');
    if (v.tag) q.tag = v.tag;
    if (v.overdue) q.overdue = '1';
    if (v.no_followup) q.no_followup = '1';
    return q;
});

const ONLY = ['filters', 'stats', 'sources', 'board', 'tags'];
const reload = () => router.get(kanbanIndex().url, query.value, { preserveState: true, preserveScroll: true, replace: true, only: ONLY });

let timer: ReturnType<typeof setTimeout>;
let first = true;
watch(f, () => {
    if (first) { first = false; }
    clearTimeout(timer);
    timer = setTimeout(reload, 300);
}, { deep: true });

// Actualización automática cada 60 s mientras la pestaña está visible.
const visibility = useDocumentVisibility();
useIntervalFn(() => { if (visibility.value === 'visible') reload(); }, 60_000);

</script>

<template>
    <Head title="Kanban" />

    <!-- Ocupa todo el alto disponible: el tablero crece y cada columna scrollea por dentro. -->
    <div class="flex h-[calc(100dvh-4rem)] min-h-[32rem] min-w-0 flex-col gap-3 p-4 pb-2 md:px-6">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <h1 class="text-2xl font-semibold tracking-tight">Kanban</h1>
                <p class="flex flex-wrap gap-x-3 text-sm text-muted-foreground">
                    <span><strong class="text-foreground tabular-nums">{{ stats.total }}</strong> prospectos</span>
                    <span><strong class="text-foreground tabular-nums">{{ stats.open }}</strong> en gestión</span>
                    <button type="button" :class="cn('rounded-md hover:text-destructive', f.overdue && 'font-semibold text-destructive')" title="Filtrar seguimientos vencidos" @click="f = { ...f, overdue: !f.overdue }"><strong class="tabular-nums" :class="stats.overdue ? 'text-destructive' : 'text-foreground'">{{ stats.overdue }}</strong> vencidos</button>
                    <span class="hidden sm:inline">· pipeline <strong class="text-foreground">{{ formatMoneyShort(stats.pipeline_value) || '$0' }}</strong></span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" as-child><Link :href="dashboard()"><LayoutDashboard /> Dashboard</Link></Button>
                <Button variant="outline" as-child><Link :href="leadsIndex()"><List /> Ver lista</Link></Button>
                <Button v-if="can.create" as-child><Link :href="create()"><Plus /> Nuevo prospecto</Link></Button>
            </div>
        </div>

        <KanbanToolbar v-model="f" :periods="periods" :sorts="sorts" :priorities="priorities" :sources="sources" :users="users" :tags="tags" :can-view-all="can.viewAll" />
        <p v-if="can.move" class="-mt-1 text-xs text-muted-foreground">Arrastra para cambiar de etapa · clic en una tarjeta para abrirla · marca la casilla para acciones masivas</p>

        <KanbanBoard
            class="min-h-0 flex-1"
            :board="board"
            :stages="stages"
            :users="users"
            :tags="tags"
            :priorities="priorities"
            :query="query"
            :manual="f.sort === 'manual'"
            :page-size="columnPage"
            :manual-source-id="manualSourceId"
            :user-id="page.props.auth.user.id"
            :can="can"
            @changed="reload"
        />
    </div>
</template>
