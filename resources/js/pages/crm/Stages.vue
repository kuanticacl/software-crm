<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { FileSignature, GripVertical, Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import ColorPicker from '@/components/ColorPicker.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { destroy, index, reorder, store, update } from '@/routes/stages';

type Stage = { id: number; name: string; color: string; type: string; leads_count: number; requires_proposal: boolean };

defineOptions({
    layout: { breadcrumbs: [{ title: 'Etapas del Kanban', href: index() }] },
});

const props = defineProps<{ stages: Stage[]; types: Record<string, string> }>();

const list = ref<Stage[]>([...props.stages]);
watch(() => props.stages, (v) => (list.value = [...v]));

const persistOrder = () => {
    router.put(reorder().url, { ids: list.value.map((s) => s.id) }, { preserveScroll: true, preserveState: true });
};

const dialogOpen = ref(false);
const editing = ref<Stage | null>(null);
const form = useForm({ name: '', color: '#1AA0E4', type: 'open', requires_proposal: false });

const openCreate = () => {
    editing.value = null;
    form.defaults({ name: '', color: '#1AA0E4', type: 'open', requires_proposal: false }).reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const openEdit = (s: Stage) => {
    editing.value = s;
    form.defaults({ name: s.name, color: s.color, type: s.type, requires_proposal: s.requires_proposal }).reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (dialogOpen.value = false) };
    editing.value ? form.submit(update(editing.value.id), opts) : form.submit(store(), opts);
};

const toDelete = ref<Stage | null>(null);
const moveTo = ref<number | string>('');
const targets = computed(() => list.value.filter((s) => s.id !== toDelete.value?.id));
const askDelete = (s: Stage) => {
    toDelete.value = s;
    moveTo.value = list.value.find((x) => x.id !== s.id)?.id ?? '';
};
const confirmDelete = () => {
    if (!toDelete.value) return;
    router.delete(destroy(toDelete.value.id).url, {
        data: { move_to: moveTo.value },
        preserveScroll: true,
        onFinish: () => (toDelete.value = null),
    });
};

const typeStyle: Record<string, string> = {
    open: 'bg-muted text-muted-foreground',
    won: 'bg-brand-green/10 text-brand-green',
    lost: 'bg-destructive/10 text-destructive',
};
</script>

<template>
    <Head title="Etapas del Kanban" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Etapas del Kanban"
            description="Arrastra para cambiar el orden. Los prospectos nuevos ingresan en la primera etapa."
        >
            <template #actions>
                <Button @click="openCreate"><Plus /> Nueva etapa</Button>
            </template>
        </PageHeader>

        <draggable
            v-model="list"
            item-key="id"
            handle=".drag-handle"
            ghost-class="opacity-40"
            :animation="180"
            class="flex max-w-3xl flex-col gap-2"
            @end="persistOrder"
        >
            <template #item="{ element: s, index: i }">
                <div class="flex items-center gap-3 rounded-2xl border bg-card p-3 pr-4 shadow-sm shadow-black/[0.03]">
                    <button type="button" class="drag-handle cursor-grab touch-none text-muted-foreground hover:text-foreground active:cursor-grabbing" title="Arrastrar para ordenar">
                        <GripVertical class="size-5" />
                    </button>
                    <span class="w-5 text-center text-xs font-semibold text-muted-foreground">{{ i + 1 }}</span>
                    <span class="size-4 shrink-0 rounded-full" :style="{ backgroundColor: s.color }" />
                    <span class="flex-1 font-medium">{{ s.name }}</span>
                    <Badge v-if="s.requires_proposal" class="border-transparent bg-primary/10 text-primary" title="Pide cotización"><FileSignature class="size-3" />Propuesta</Badge>
                    <Badge :class="['border-transparent', typeStyle[s.type]]">{{ types[s.type] }}</Badge>
                    <span class="w-16 text-right text-xs text-muted-foreground">{{ s.leads_count }} leads</span>
                    <Button variant="ghost" size="icon-sm" title="Editar" @click="openEdit(s)"><Pencil /></Button>
                    <Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" :disabled="list.length <= 1" @click="askDelete(s)">
                        <Trash2 />
                    </Button>
                </div>
            </template>
        </draggable>

        <p class="max-w-3xl text-sm text-muted-foreground">
            El <strong>tipo</strong> define cómo se cuentan los prospectos en el dashboard:
            <em>En curso</em> (en gestión), <em>Concretado</em> (venta ganada) o
            <em>Descartado</em> (perdido).
        </p>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ editing ? 'Editar etapa' : 'Nueva etapa' }}</DialogTitle>
                <DialogDescription>Nombre, color y tipo de la etapa.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <FormField label="Nombre" for="st-name" :error="form.errors.name" required>
                    <Input id="st-name" v-model="form.name" placeholder="Ej: Visita en sala" />
                </FormField>
                <FormField label="Color" :error="form.errors.color"><ColorPicker v-model="form.color" /></FormField>
                <FormField label="Tipo" for="st-type" :error="form.errors.type">
                    <NativeSelect id="st-type" v-model="form.type">
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </NativeSelect>
                </FormField>
                <label class="flex items-start gap-3 rounded-xl border bg-muted/30 p-3 text-sm">
                    <Switch :model-value="form.requires_proposal" class="mt-0.5" @update:model-value="(v: boolean) => (form.requires_proposal = v)" />
                    <span><strong>Requiere cotización</strong><span class="block text-xs text-muted-foreground">Al mover un prospecto a esta etapa sin cotización, el CRM te ofrece crearla.</span></span>
                </label>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="dialogOpen = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="!!toDelete" @update:open="(v: boolean) => !v && (toDelete = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Eliminar etapa «{{ toDelete?.name }}»</DialogTitle>
                <DialogDescription>
                    <template v-if="toDelete?.leads_count">
                        Tiene {{ toDelete.leads_count }} leads. Elige a qué etapa se moverán.
                    </template>
                    <template v-else>Esta etapa no tiene leads. Esta acción no se puede deshacer.</template>
                </DialogDescription>
            </DialogHeader>
            <FormField v-if="toDelete?.leads_count" label="Mover prospectos a" for="move-to">
                <NativeSelect id="move-to" v-model="moveTo">
                    <option v-for="t in targets" :key="t.id" :value="t.id">{{ t.name }}</option>
                </NativeSelect>
            </FormField>
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="toDelete = null">Cancelar</Button>
                <Button variant="destructive" @click="confirmDelete">Eliminar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
