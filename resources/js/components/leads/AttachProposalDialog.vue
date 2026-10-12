<script setup lang="ts">
import { Link2, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { sendJson } from '@/lib/http';
import { formatAmount } from '@/lib/leadUi';

type Row = { id: number; number: string; title: string; company: string | null; status_label: string; status_color: string; currency: string; total_net: number; lead: { id: number; name: string } | null };
const props = defineProps<{ open: boolean; leadId: number }>();
const emit = defineEmits<{ 'update:open': [boolean]; attached: [] }>();

const q = ref('');
const rows = ref<Row[]>([]);
const loading = ref(false);
const saving = ref<number | null>(null);

const load = async () => {
    loading.value = true;
    try {
        const params = new URLSearchParams({ exclude_lead: String(props.leadId) });
        if (q.value.trim()) params.set('q', q.value.trim());
        rows.value = (await sendJson<{ proposals: Row[] }>('GET', `/proposals/search?${params}`)).proposals;
    } catch {
        rows.value = [];
        toast.error('No se pudo buscar propuestas.');
    } finally {
        loading.value = false;
    }
};
const debounced = useDebounceFn(load, 250);
watch(q, debounced);
watch(
    () => props.open,
    (o) => {
        if (o) {
            q.value = '';
            load();
        }
    },
);

const attach = async (r: Row) => {
    saving.value = r.id;
    try {
        await sendJson('POST', `/leads/${props.leadId}/proposals/attach`, { proposal_id: r.id });
        toast.success(`Propuesta ${r.number} asociada.`);
        emit('attached');
        emit('update:open', false);
    } catch {
        toast.error('No se pudo asociar la propuesta.');
    } finally {
        saving.value = null;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Asociar una propuesta existente</DialogTitle>
                <DialogDescription>Busca por número, título o empresa. Si la propuesta estaba en otro prospecto, se mueve a este.</DialogDescription>
            </DialogHeader>
            <div class="relative">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="q" class="pl-9" placeholder="Buscar propuestas…" autofocus />
            </div>
            <div class="max-h-[22rem] overflow-y-auto rounded-xl border">
                <div v-if="loading" class="flex items-center justify-center gap-2 p-6 text-sm text-muted-foreground"><Spinner class="size-4" /> Buscando…</div>
                <p v-else-if="!rows.length" class="p-6 text-center text-sm text-muted-foreground">No hay propuestas para asociar{{ q ? ' con esa búsqueda' : '' }}.</p>
                <ul v-else class="divide-y">
                    <li v-for="r in rows" :key="r.id" class="flex items-center gap-3 p-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2"><span class="truncate text-sm font-semibold">{{ r.title }}</span><span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: r.status_color }">{{ r.status_label }}</span></div>
                            <p class="truncate text-xs text-muted-foreground">{{ r.number }}<template v-if="r.company"> · {{ r.company }}</template> · {{ formatAmount(r.total_net, r.currency) }}</p>
                            <p v-if="r.lead" class="text-xs text-amber-600">Hoy asociada a: {{ r.lead.name }}</p>
                            <p v-else class="text-xs text-muted-foreground">Sin prospecto asociado</p>
                        </div>
                        <Button size="sm" variant="outline" :disabled="saving !== null" @click="attach(r)"><Spinner v-if="saving === r.id" /><Link2 v-else /> Asociar</Button>
                    </li>
                </ul>
            </div>
        </DialogContent>
    </Dialog>
</template>
