<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { GripVertical, LayoutPanelTop, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import draggable from 'vuedraggable';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { destroy, index, reorder, store, update } from '@/routes/fields';

type Field = {
    id: number;
    label: string;
    key: string;
    type: string;
    options: string[] | null;
    is_required: boolean;
    show_on_card: boolean;
    is_active: boolean;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Campos personalizados', href: index() }] },
});

const props = defineProps<{ fields: Field[]; types: Record<string, string> }>();

const list = ref<Field[]>([...props.fields]);
watch(() => props.fields, (v) => (list.value = [...v]));

const persistOrder = () => {
    router.put(reorder().url, { ids: list.value.map((f) => f.id) }, { preserveScroll: true, preserveState: true });
};

const dialogOpen = ref(false);
const editing = ref<Field | null>(null);
const optionsText = ref('');
const form = useForm({
    label: '',
    type: 'text',
    options: [] as string[],
    is_required: false,
    show_on_card: false,
    is_active: true,
});

const openCreate = () => {
    editing.value = null;
    optionsText.value = '';
    form.defaults({ label: '', type: 'text', options: [], is_required: false, show_on_card: false, is_active: true }).reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const openEdit = (f: Field) => {
    editing.value = f;
    optionsText.value = (f.options ?? []).join('\n');
    form.defaults({ label: f.label, type: f.type, options: f.options ?? [], is_required: f.is_required, show_on_card: f.show_on_card, is_active: f.is_active }).reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const save = () => {
    form.options = optionsText.value.split('\n').map((o) => o.trim()).filter(Boolean);
    const opts = { preserveScroll: true, onSuccess: () => (dialogOpen.value = false) };
    editing.value ? form.submit(update(editing.value.id), opts) : form.submit(store(), opts);
};

const toDelete = ref<Field | null>(null);
const confirmDelete = () => {
    if (!toDelete.value) return;
    router.delete(destroy(toDelete.value.id).url, {
        preserveScroll: true,
        onFinish: () => (toDelete.value = null),
    });
};
</script>

<template>
    <Head title="Campos personalizados" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Campos personalizados"
            description="Agrega datos propios a los prospectos además de nombre, apellido, correo, teléfono, cargo y empresa."
        >
            <template #actions>
                <Button @click="openCreate"><Plus /> Nuevo campo</Button>
            </template>
        </PageHeader>

        <div v-if="!list.length" class="max-w-3xl rounded-2xl border border-dashed bg-card p-10 text-center text-muted-foreground">
            <LayoutPanelTop class="mx-auto mb-3 size-8" />
            Aún no hay campos personalizados. Crea uno, por ejemplo «Proyecto de interés» o «Presupuesto».
        </div>

        <draggable v-model="list" item-key="id" handle=".drag-handle" ghost-class="opacity-40" :animation="180" class="flex max-w-3xl flex-col gap-2" @end="persistOrder">
            <template #item="{ element: f }">
                <div class="flex items-center gap-3 rounded-2xl border bg-card p-3 pr-4 shadow-sm shadow-black/[0.03]" :class="!f.is_active && 'opacity-60'">
                    <button type="button" class="drag-handle cursor-grab touch-none text-muted-foreground hover:text-foreground active:cursor-grabbing" title="Arrastrar para ordenar">
                        <GripVertical class="size-5" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ f.label }}</p>
                        <p class="text-xs text-muted-foreground">clave: <code>{{ f.key }}</code></p>
                    </div>
                    <Badge variant="outline">{{ types[f.type] }}</Badge>
                    <Badge v-if="f.is_required" variant="secondary">Obligatorio</Badge>
                    <Badge v-if="f.show_on_card" class="border-transparent bg-accent text-accent-foreground">En tarjeta</Badge>
                    <Badge v-if="!f.is_active" variant="secondary">Inactivo</Badge>
                    <Button variant="ghost" size="icon-sm" title="Editar" @click="openEdit(f)"><Pencil /></Button>
                    <Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" @click="toDelete = f"><Trash2 /></Button>
                </div>
            </template>
        </draggable>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ editing ? 'Editar campo' : 'Nuevo campo' }}</DialogTitle>
                <DialogDescription>
                    {{ editing ? `La clave «${editing.key}» no cambia (la usa la API).` : 'Se generará una clave a partir del nombre para usarla en la API.' }}
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <FormField label="Nombre del campo" for="f-label" :error="form.errors.label" required>
                    <Input id="f-label" v-model="form.label" placeholder="Ej: Proyecto de interés" />
                </FormField>
                <FormField label="Tipo" for="f-type" :error="form.errors.type">
                    <NativeSelect id="f-type" v-model="form.type">
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </NativeSelect>
                </FormField>
                <FormField v-if="form.type === 'select'" label="Opciones" for="f-options" hint="Una por línea." :error="form.errors.options">
                    <Textarea id="f-options" v-model="optionsText" rows="4" placeholder="Torre Norte&#10;Torre Sur" />
                </FormField>
                <div class="grid gap-3 text-sm">
                    <label class="flex items-center gap-3">
                        <Switch :model-value="form.is_required" @update:model-value="(v: boolean) => (form.is_required = v)" /> Obligatorio al crear un prospecto manualmente
                    </label>
                    <label class="flex items-center gap-3">
                        <Switch :model-value="form.show_on_card" @update:model-value="(v: boolean) => (form.show_on_card = v)" /> Mostrar en la tarjeta del Kanban
                    </label>
                    <label class="flex items-center gap-3">
                        <Switch :model-value="form.is_active" @update:model-value="(v: boolean) => (form.is_active = v)" /> Activo
                    </label>
                </div>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="dialogOpen = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="!!toDelete"
        title="Eliminar campo"
        :description="`Se eliminará «${toDelete?.label}». Los valores ya guardados en los prospectos se conservan.`"
        confirm-label="Eliminar"
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="confirmDelete"
    />
</template>
