<script setup lang="ts">
import { ChevronDown, ChevronsLeftRight, ListChecks, Plus } from '@lucide/vue';
import draggable from 'vuedraggable';
import KanbanCard from '@/components/kanban/KanbanCard.vue';
import type { KanbanPrefs } from '@/composables/useKanbanPrefs';
import { formatMoneyShort } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import type { BoardColumn, LeadCard } from '@/types';

type DragChange = { added?: { element: LeadCard; newIndex: number }; moved?: { element: LeadCard; newIndex: number } };

const props = defineProps<{
    col: BoardColumn;
    prefs: KanbanPrefs;
    selected: Set<number>;
    canMove: boolean;
    canCreate: boolean;
    collapsed: boolean;
    pageSize: number;
    loading: boolean;
}>();
const emit = defineEmits<{
    change: [DragChange];
    open: [LeadCard];
    select: [LeadCard];
    selectColumn: [];
    add: [];
    toggleCollapse: [];
    loadMore: [];
}>();

const remaining = () => props.col.total - props.col.leads.length;
</script>

<template>
    <!-- Columna colapsada -->
    <button
        v-if="collapsed"
        type="button"
        class="flex h-full w-12 shrink-0 flex-col items-center gap-3 rounded-2xl bg-muted/60 py-3 ring-1 ring-border/60 transition hover:bg-muted"
        :title="`Expandir ${col.name}`"
        @click="emit('toggleCollapse')"
    >
        <span class="size-3 rounded-full" :style="{ backgroundColor: col.color }" />
        <span class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold text-white" :style="{ backgroundColor: col.color }">{{ col.total }}</span>
        <span class="text-xs font-semibold [writing-mode:vertical-rl]">{{ col.name }}</span>
        <ChevronsLeftRight class="mt-auto size-4 text-muted-foreground" />
    </button>

    <section v-else class="flex h-full min-h-64 w-[19.5rem] shrink-0 snap-start flex-col rounded-2xl bg-muted/60 ring-1 ring-border/60">
        <header class="px-3.5 pt-3 pb-2">
            <div class="flex items-center gap-2">
                <span class="size-3 rounded-full" :style="{ backgroundColor: col.color }" />
                <h3 class="flex-1 truncate text-sm font-semibold">{{ col.name }}</h3>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: col.color }">{{ col.total }}</span>
                <button v-if="canCreate && col.type === 'open'" type="button" class="rounded-md p-1 text-muted-foreground hover:bg-background hover:text-primary" :title="`Nuevo prospecto en ${col.name}`" @click="emit('add')"><Plus class="size-4" /></button>
                <button type="button" class="rounded-md p-1 text-muted-foreground hover:bg-background hover:text-foreground" title="Seleccionar todas las tarjetas" @click="emit('selectColumn')"><ListChecks class="size-4" /></button>
                <button type="button" class="rounded-md p-1 text-muted-foreground hover:bg-background hover:text-foreground" title="Colapsar columna" @click="emit('toggleCollapse')"><ChevronsLeftRight class="size-4 rotate-180" /></button>
            </div>
            <p class="mt-1 h-4 pl-5 text-[11px] text-muted-foreground">
                <template v-if="col.value > 0">{{ formatMoneyShort(col.value) }} en valor estimado</template>
                <template v-else-if="col.type === 'won'">Negocios concretados</template>
                <template v-else-if="col.type === 'lost'">Oportunidades descartadas</template>
            </p>
            <div class="mt-1 h-0.5 rounded-full" :style="{ backgroundColor: col.color }" />
        </header>

        <draggable
            v-model="col.leads"
            group="leads"
            item-key="id"
            :disabled="!canMove"
            :animation="160"
            :scroll-sensitivity="120"
            :scroll-speed="16"
            :delay="120"
            :delay-on-touch-only="true"
            ghost-class="opacity-30"
            class="flex min-h-16 flex-1 flex-col gap-2 overflow-y-auto px-2.5 pb-2.5"
            @change="(c: DragChange) => emit('change', c)"
        >
            <template #item="{ element }">
                <KanbanCard
                    :lead="element"
                    :prefs="prefs"
                    :selected="selected.has(element.id)"
                    :selecting="selected.size > 0"
                    :closed="col.type !== 'open'"
                    @open="emit('open', element)"
                    @select="emit('select', element)"
                />
            </template>
            <template #footer>
                <p v-if="!col.leads.length" class="rounded-xl border border-dashed py-6 text-center text-xs text-muted-foreground">
                    {{ canMove ? 'Arrastra prospectos aquí' : 'Sin prospectos' }}
                </p>
            </template>
        </draggable>

        <button
            v-if="remaining() > 0"
            type="button"
            :class="cn('mx-2.5 mb-2.5 flex items-center justify-center gap-1 rounded-xl py-2 text-xs font-medium text-primary hover:bg-accent disabled:opacity-60')"
            :disabled="loading"
            @click="emit('loadMore')"
        >
            <ChevronDown class="size-3.5" /> Cargar {{ Math.min(pageSize, remaining()) }} más ({{ remaining() }} restantes)
        </button>
    </section>
</template>
