<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import BulkBar from '@/components/kanban/BulkBar.vue';
import CloseStageDialog from '@/components/kanban/CloseStageDialog.vue';
import KanbanColumn from '@/components/kanban/KanbanColumn.vue';
import LeadDrawer from '@/components/kanban/LeadDrawer.vue';
import QuickCreateDialog from '@/components/kanban/QuickCreateDialog.vue';
import { useKanbanPrefs } from '@/composables/useKanbanPrefs';
import { sendJson } from '@/lib/http';
import { column } from '@/routes/kanban';
import { bulk, move } from '@/routes/leads';
import type { BoardColumn, LeadCard, StageRef, UserOption } from '@/types';

type DragChange = { added?: { element: LeadCard; newIndex: number }; moved?: { element: LeadCard; newIndex: number } };

const props = defineProps<{
    board: BoardColumn[];
    stages: StageRef[];
    users: UserOption[];
    tags: string[];
    priorities: Record<string, string>;
    query: Record<string, string>;
    manual: boolean;
    pageSize: number;
    manualSourceId: number | null;
    userId: number;
    can: { create: boolean; move: boolean; assign: boolean; update: boolean; delete: boolean };
}>();
const emit = defineEmits<{ changed: [] }>();

const prefs = useKanbanPrefs();

// Copia local editable (las props son de solo lectura).
const clone = (v: BoardColumn[]): BoardColumn[] => JSON.parse(JSON.stringify(v));
const columns = ref<BoardColumn[]>(clone(props.board));
watch(() => props.board, (v) => {
    columns.value = clone(v);
    // descarta selecciones de leads que ya no están visibles
    const visible = new Set(columns.value.flatMap((c) => c.leads.map((l) => l.id)));
    selected.value = new Set([...selected.value].filter((id) => visible.has(id)));
});

const refresh = () => emit('changed');

// ---- selección múltiple ----
const selected = ref<Set<number>>(new Set());
const toggle = (lead: LeadCard) => {
    const next = new Set(selected.value);
    next.has(lead.id) ? next.delete(lead.id) : next.add(lead.id);
    selected.value = next;
};
const selectColumn = (col: BoardColumn) => {
    const ids = col.leads.map((l) => l.id);
    const all = ids.every((id) => selected.value.has(id));
    const next = new Set(selected.value);
    ids.forEach((id) => (all ? next.delete(id) : next.add(id)));
    selected.value = next;
};

// ---- arrastre ----
type Pending = { lead: LeadCard; col: BoardColumn; afterId: number | null; fromStageId: number };
const pending = ref<Pending | null>(null);

const persistMove = async (lead: LeadCard, col: BoardColumn, afterId: number | null, extra: Record<string, unknown> = {}) => {
    try {
        const res = await sendJson<{ needs_proposal?: boolean }>('PUT', move(lead.id).url, { stage_id: col.id, after_id: props.manual ? afterId : null, ...extra });
        refresh();
        if (res.needs_proposal) {
            toast(`«${lead.full_name}» está en ${col.name} y aún no tiene propuesta`, { action: { label: 'Crear propuesta', onClick: () => router.visit(`/proposals/create?lead=${lead.id}`) }, duration: 9000 });
        }
    } catch {
        toast.error('No se pudo mover el prospecto. Se restauró el tablero.');
        refresh();
    }
};

const onChange = (col: BoardColumn, change: DragChange) => {
    const evt = change.added ?? change.moved;
    if (!evt) return;
    const lead = evt.element;
    const afterId = col.leads[evt.newIndex - 1]?.id ?? null;

    if (change.added) {
        const fromId = lead.stage_id;
        const from = columns.value.find((c) => c.id === fromId);
        if (from) from.total = Math.max(0, from.total - 1);
        col.total += 1;
        lead.stage_id = col.id;

        if (col.type !== 'open') {
            pending.value = { lead, col, afterId, fromStageId: fromId };
            return;
        }
    }
    persistMove(lead, col, afterId);
};

const confirmPending = (extra: { lost_reason: string | null; estimated_amount: number | null; estimated_currency: 'CLP' | 'UF' }) => {
    const p = pending.value;
    pending.value = null;
    if (p) persistMove(p.lead, p.col, p.afterId, extra);
};
const cancelPending = () => {
    pending.value = null;
    refresh(); // devuelve la tarjeta a su columna original
};

// ---- cargar más ----
const loading = ref<number | null>(null);
const loadMore = async (col: BoardColumn) => {
    loading.value = col.id;
    try {
        const qs = new URLSearchParams({ ...props.query, offset: String(col.leads.length) });
        const data = await sendJson<{ leads: LeadCard[] }>('GET', `${column(col.id).url}?${qs}`);
        const known = new Set(col.leads.map((l) => l.id));
        col.leads.push(...data.leads.filter((l) => !known.has(l.id)));
    } catch {
        toast.error('No se pudieron cargar más leads.');
    } finally {
        loading.value = null;
    }
};

// ---- colapsar ----
const isCollapsed = (id: number) => prefs.value.collapsed.includes(id);
const toggleCollapse = (id: number) => {
    prefs.value.collapsed = isCollapsed(id) ? prefs.value.collapsed.filter((x) => x !== id) : [...prefs.value.collapsed, id];
};

// ---- panel lateral ----
const openId = ref<number | null>(null);

// ---- creación rápida ----
const quick = ref<{ stageId: number; stageName: string } | null>(null);
const onCreated = (id: number) => {
    toast.success('Prospecto creado');
    refresh();
    openId.value = id;
};

// ---- acciones masivas ----
const busy = ref(false);
const bulkClosing = ref<{ stage: StageRef & { type?: string }; payload: Record<string, unknown> } | null>(null);
const runBulk = async (payload: Record<string, unknown>) => {
    busy.value = true;
    try {
        const res = await sendJson<{ done: number; skipped: number }>('POST', bulk().url, { ids: [...selected.value], ...payload });
        toast.success(`${res.done} lead${res.done === 1 ? '' : 's'} actualizado${res.done === 1 ? '' : 's'}${res.skipped ? ` · ${res.skipped} sin permiso` : ''}`);
        selected.value = new Set();
        refresh();
    } catch {
        toast.error('No se pudo completar la acción masiva.');
    } finally {
        busy.value = false;
    }
};
const onBulk = (payload: { action: string; stage_id?: number }) => {
    if (payload.action === 'move') {
        const target = columns.value.find((c) => c.id === payload.stage_id);
        if (target && target.type !== 'open') {
            bulkClosing.value = { stage: { id: target.id, name: target.name, color: target.color, type: target.type }, payload };
            return;
        }
    }
    if (payload.action === 'delete' && !window.confirm(`¿Eliminar ${selected.value.size} prospectos? Esta acción no se puede deshacer.`)) return;
    runBulk(payload);
};
const confirmBulkClose = (extra: { lost_reason: string | null }) => {
    const c = bulkClosing.value;
    bulkClosing.value = null;
    if (c) runBulk({ ...c.payload, lost_reason: extra.lost_reason });
};
</script>

<template>
    <div class="-mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-2 md:mx-0 md:px-0">
        <KanbanColumn
            v-for="col in columns"
            :key="col.id"
            :col="col"
            :prefs="prefs"
            :selected="selected"
            :can-move="can.move"
            :can-create="can.create"
            :collapsed="isCollapsed(col.id)"
            :page-size="pageSize"
            :loading="loading === col.id"
            @change="(c) => onChange(col, c)"
            @open="(l) => (openId = l.id)"
            @select="toggle"
            @select-column="selectColumn(col)"
            @add="quick = { stageId: col.id, stageName: col.name }"
            @toggle-collapse="toggleCollapse(col.id)"
            @load-more="loadMore(col)"
        />
    </div>

    <BulkBar
        v-if="selected.size"
        :count="selected.size"
        :stages="stages"
        :users="users"
        :priorities="priorities"
        :can="can"
        :busy="busy"
        @clear="selected = new Set()"
        @action="onBulk"
    />

    <LeadDrawer :lead-id="openId" :tags="tags" @close="openId = null" @changed="refresh" />

    <CloseStageDialog
        :open="!!pending"
        :type="pending && pending.col.type !== 'open' ? pending.col.type : null"
        :stage-name="pending?.col.name ?? ''"
        :lead-name="pending?.lead.full_name"
        @update:open="(v: boolean) => !v && cancelPending()"
        @confirm="confirmPending"
        @cancel="() => {}"
    />
    <CloseStageDialog
        :open="!!bulkClosing"
        :type="(bulkClosing?.stage.type as 'won' | 'lost' | undefined) ?? null"
        :stage-name="bulkClosing?.stage.name ?? ''"
        :count="selected.size"
        @update:open="(v: boolean) => !v && (bulkClosing = null)"
        @confirm="confirmBulkClose"
    />

    <QuickCreateDialog
        :open="!!quick"
        :stage-id="quick?.stageId ?? null"
        :stage-name="quick?.stageName ?? ''"
        :manual-source-id="manualSourceId"
        :user-id="userId"
        :priorities="priorities"
        @update:open="(v: boolean) => !v && (quick = null)"
        @created="onCreated"
    />
</template>
