<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import MoneyInput from '@/components/MoneyInput.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { HttpError, sendJson } from '@/lib/http';
import { store } from '@/routes/leads';

const props = defineProps<{
    open: boolean;
    stageId: number | null;
    stageName: string;
    manualSourceId: number | null;
    userId: number;
    priorities: Record<string, string>;
}>();
const emit = defineEmits<{ 'update:open': [boolean]; created: [number] }>();

const blank = () => ({ first_name: '', last_name: '', email: '', phone: '', company: '', estimated_amount: '', estimated_currency: 'CLP' as 'CLP' | 'UF', priority: 'normal' });
const f = reactive(blank());
const errors = ref<Record<string, string>>({});
const busy = ref(false);

watch(() => props.open, (o) => {
    if (o) {
        Object.assign(f, blank());
        errors.value = {};
    }
});

const submit = async () => {
    busy.value = true;
    errors.value = {};
    try {
        const res = await sendJson<{ id: number }>('POST', store().url, {
            ...f,
            estimated_amount: f.estimated_amount === '' ? null : Number(f.estimated_amount),
            stage_id: props.stageId,
            source_id: props.manualSourceId,
            assigned_to: props.userId,
        });
        emit('created', res.id);
        emit('update:open', false);
    } catch (e) {
        errors.value = e instanceof HttpError ? e.fieldErrors : { first_name: 'No se pudo crear el prospecto.' };
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nuevo prospecto en «{{ stageName }}»</DialogTitle>
                <DialogDescription>Creación rápida. Podrás completar el resto desde el panel del prospecto.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <FormField label="Nombre" for="qc-first" :error="errors.first_name" required><Input id="qc-first" v-model="f.first_name" autofocus /></FormField>
                <FormField label="Apellido" for="qc-last" :error="errors.last_name"><Input id="qc-last" v-model="f.last_name" /></FormField>
                <FormField label="Correo" for="qc-email" :error="errors.email"><Input id="qc-email" v-model="f.email" type="email" /></FormField>
                <FormField label="Teléfono" for="qc-phone" :error="errors.phone"><Input id="qc-phone" v-model="f.phone" /></FormField>
                <FormField label="Empresa" for="qc-company" :error="errors.company"><Input id="qc-company" v-model="f.company" /></FormField>
                <FormField label="Valor estimado" for="qc-value" :error="errors.estimated_amount"><MoneyInput id="qc-value" v-model="f.estimated_amount" v-model:currency="f.estimated_currency" /></FormField>
                <FormField label="Prioridad" for="qc-prio" class="sm:col-span-2">
                    <NativeSelect id="qc-prio" v-model="f.priority"><option v-for="(label, key) in priorities" :key="key" :value="key">{{ label }}</option></NativeSelect>
                </FormField>
                <DialogFooter class="gap-2 sm:col-span-2">
                    <Button type="button" variant="outline" @click="emit('update:open', false)">Cancelar</Button>
                    <Button type="submit" :disabled="busy || !f.first_name.trim()"><Spinner v-if="busy" /> Crear prospecto</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
