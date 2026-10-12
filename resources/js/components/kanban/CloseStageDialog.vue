<script setup lang="ts">
import { ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import MoneyInput from '@/components/MoneyInput.vue';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { lostReasons } from '@/lib/leadUi';

const props = defineProps<{
    open: boolean;
    type: 'won' | 'lost' | null;
    stageName: string;
    count?: number;
    leadName?: string;
}>();
const emit = defineEmits<{
    'update:open': [boolean];
    confirm: [{ lost_reason: string | null; estimated_amount: number | null; estimated_currency: 'CLP' | 'UF' }];
    cancel: [];
}>();

const preset = ref(lostReasons[0]);
const other = ref('');
const value = ref('');
const currency = ref<'CLP' | 'UF'>('CLP');

watch(
    () => props.open,
    (o) => {
        if (o) {
            preset.value = lostReasons[0];
            other.value = '';
            value.value = '';
            currency.value = 'CLP';
        }
    },
);

const submit = () => {
    emit('confirm', {
        lost_reason: props.type === 'lost' ? (preset.value === 'Otro' ? other.value.trim() || 'Otro' : preset.value) : null,
        estimated_amount: props.type === 'won' && value.value ? Number(value.value) : null,
        estimated_currency: currency.value,
    });
};
const close = (v: boolean) => {
    if (!v) emit('cancel');
    emit('update:open', v);
};
</script>

<template>
    <Dialog :open="open" @update:open="close">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>
                    {{ type === 'won' ? '🎉 Marcar como concretado' : 'Descartar prospecto' }}
                    <template v-if="count && count > 1"> ({{ count }} prospectos)</template>
                </DialogTitle>
                <DialogDescription>
                    <template v-if="type === 'won'">
                        {{ leadName ?? 'El prospecto' }} pasará a «{{ stageName }}». Puedes registrar el valor final del negocio.
                    </template>
                    <template v-else>
                        {{ leadName ?? 'El prospecto' }} pasará a «{{ stageName }}». Indica el motivo para poder analizar por qué se pierden oportunidades.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit">
                <template v-if="type === 'lost'">
                    <FormField label="Motivo" for="lost-reason">
                        <NativeSelect id="lost-reason" v-model="preset">
                            <option v-for="r in lostReasons" :key="r" :value="r">{{ r }}</option>
                            <option value="Otro">Otro…</option>
                        </NativeSelect>
                    </FormField>
                    <FormField v-if="preset === 'Otro'" label="Detalle" for="lost-other">
                        <Input id="lost-other" v-model="other" placeholder="Describe el motivo" />
                    </FormField>
                </template>
                <FormField v-else label="Valor final" for="won-value" hint="Opcional. Reemplaza el valor estimado.">
                    <MoneyInput id="won-value" v-model="value" v-model:currency="currency" placeholder="Ej: 4500000" />
                </FormField>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="close(false)">Cancelar</Button>
                    <Button type="submit" :variant="type === 'lost' ? 'destructive' : 'default'">
                        {{ type === 'won' ? 'Concretar' : 'Descartar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
