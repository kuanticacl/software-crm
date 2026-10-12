<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import { ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { clp, toClp, type UnitRow } from '@/lib/realEstate';

const props = defineProps<{ unit: UnitRow | null; projectId: number; types: Record<string, string>; orientations: string[]; models: string[]; uf: { value: number } | null }>();
const open = defineModel<boolean>('open', { required: true });

const blank = () => ({
    project_id: props.projectId, type: 'departamento', code: '', tower: '', model: '', floor: undefined as number | undefined, orientation: '', bedrooms: undefined as number | undefined, bathrooms: undefined as number | undefined,
    program: '', surface_useful: undefined as number | undefined, surface_terrace: undefined as number | undefined, surface_total: undefined as number | undefined, surface_lot: undefined as number | undefined,
    list_price: undefined as number | undefined, status: 'disponible', features: '' as string, notes: '', floor_plan: null as File | null, plan_for_model: true,
});
const form = useForm(blank());
const preview = ref<string | null>(null);

watch(open, (v) => {
    if (!v) return;
    const u = props.unit;
    form.clearErrors();
    form.defaults(u ? {
        ...blank(),
        project_id: u.project_id, type: u.type, code: u.code, tower: u.tower ?? '', model: u.model ?? '', floor: u.floor ?? undefined, orientation: u.orientation ?? '',
        bedrooms: u.bedrooms ?? undefined, bathrooms: u.bathrooms ?? undefined, program: u.program ?? '', surface_useful: u.surface_useful ?? undefined, surface_terrace: u.surface_terrace ?? undefined,
        surface_total: u.surface_total ?? undefined, surface_lot: u.surface_lot ?? undefined, list_price: u.list_price, notes: u.notes ?? '',
        // Reservada/promesada/vendida no se edita aquí: no se envía el estado.
        status: ['disponible', 'bloqueada'].includes(u.status) ? u.status : '',
        features: (u.features ?? []).join(', '),
    } : blank()).reset();
    preview.value = u?.floor_plan ?? null;
});

const onPlan = (e: Event) => {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.floor_plan = f;
    preview.value = f ? URL.createObjectURL(f) : props.unit?.floor_plan ?? null;
};
const isMain = () => ['departamento', 'casa', 'oficina', 'local'].includes(form.type);

const submit = () =>
    form
        .transform((d) => ({ ...d, features: d.features ? d.features.split(',').map((x: string) => x.trim()).filter(Boolean) : [], plan_for_model: d.plan_for_model ? 1 : 0 }))
        .post(props.unit ? `/units/${props.unit.id}` : '/units', { forceFormData: true, preserveScroll: true, onSuccess: () => (open.value = false) });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92dvh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>{{ unit ? `Editar ${types[unit.type]} ${unit.code}` : 'Nueva unidad' }}</DialogTitle>
                <DialogDescription>Precio de lista en UF. El estado «reservada», «promesada» y «vendida» lo maneja el CRM con las reservas.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-5" @submit.prevent="submit">
                <div class="grid items-start gap-4 sm:grid-cols-3">
                    <FormField label="Tipo" for="u-type" :error="form.errors.type"><NativeSelect id="u-type" v-model="form.type"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></NativeSelect></FormField>
                    <FormField label="Número / código" for="u-code" :error="form.errors.code" required><Input id="u-code" v-model="form.code" placeholder="1204, E-15, B-03" /></FormField>
                    <FormField label="Precio de lista (UF)" for="u-price" :error="form.errors.list_price" required :hint="uf && form.list_price ? `≈ ${clp(toClp(form.list_price, uf.value))}` : undefined"><Input id="u-price" v-model.number="form.list_price" type="number" min="0" step="0.01" /></FormField>
                    <FormField label="Torre / etapa" for="u-tower" :error="form.errors.tower"><Input id="u-tower" v-model="form.tower" /></FormField>
                    <FormField label="Modelo / tipología" for="u-model" :error="form.errors.model"><Input id="u-model" v-model="form.model" list="u-models" placeholder="A1" /><datalist id="u-models"><option v-for="m in models" :key="m" :value="m" /></datalist></FormField>
                    <FormField label="Piso" for="u-floor" :error="form.errors.floor"><Input id="u-floor" v-model.number="form.floor" type="number" min="-10" max="200" /></FormField>
                    <template v-if="isMain()">
                        <FormField label="Dormitorios" for="u-bed" :error="form.errors.bedrooms"><Input id="u-bed" v-model.number="form.bedrooms" type="number" min="0" max="20" /></FormField>
                        <FormField label="Baños" for="u-bath" :error="form.errors.bathrooms"><Input id="u-bath" v-model.number="form.bathrooms" type="number" min="0" max="20" /></FormField>
                        <FormField label="Programa" for="u-prog" :error="form.errors.program" hint="Vacío = se arma con dorm. y baños"><Input id="u-prog" v-model="form.program" placeholder="3D+2B, Estudio…" /></FormField>
                        <FormField label="Orientación" for="u-or" :error="form.errors.orientation"><Input id="u-or" v-model="form.orientation" list="u-ors" /><datalist id="u-ors"><option v-for="o in orientations" :key="o" :value="o" /></datalist></FormField>
                    </template>
                    <FormField label="Sup. útil / interior (m²)" for="u-su" :error="form.errors.surface_useful"><Input id="u-su" v-model.number="form.surface_useful" type="number" min="0" step="0.01" /></FormField>
                    <FormField v-if="isMain()" label="Terraza (m²)" for="u-st" :error="form.errors.surface_terrace"><Input id="u-st" v-model.number="form.surface_terrace" type="number" min="0" step="0.01" /></FormField>
                    <FormField v-if="isMain()" label="Sup. total (m²)" for="u-stot" :error="form.errors.surface_total"><Input id="u-stot" v-model.number="form.surface_total" type="number" min="0" step="0.01" /></FormField>
                    <FormField v-if="form.type === 'casa'" label="Terreno (m²)" for="u-lot" :error="form.errors.surface_lot"><Input id="u-lot" v-model.number="form.surface_lot" type="number" min="0" step="0.01" /></FormField>
                    <FormField v-if="!unit || ['disponible', 'bloqueada'].includes(unit.status)" label="Estado" for="u-status"><NativeSelect id="u-status" v-model="form.status"><option value="disponible">Disponible</option><option value="bloqueada">Bloqueada</option></NativeSelect></FormField>
                    <FormField label="Características" for="u-feat" :error="form.errors.features" hint="Separadas por coma: logia, vista despejada, walk-in…" class="sm:col-span-3"><Input id="u-feat" v-model="form.features" /></FormField>
                    <FormField label="Notas internas" for="u-notes" :error="form.errors.notes" class="sm:col-span-3"><Textarea id="u-notes" v-model="form.notes" rows="2" /></FormField>
                </div>

                <div class="grid gap-3 rounded-xl border p-4 sm:grid-cols-[180px_1fr]">
                    <div class="flex h-36 items-center justify-center overflow-hidden rounded-lg border bg-muted/30">
                        <img v-if="preview" :src="preview" alt="Planta" class="max-h-full max-w-full object-contain" />
                        <span v-else class="px-3 text-center text-xs text-muted-foreground">Sin planta</span>
                    </div>
                    <div class="grid content-start gap-3">
                        <p class="text-sm font-medium">Planta de la unidad</p>
                        <label class="inline-flex w-fit cursor-pointer items-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-muted"><ImagePlus class="size-4" /> {{ preview ? 'Cambiar planta' : 'Subir planta' }}<input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onPlan" /></label>
                        <label v-if="form.model" class="flex items-center gap-2 text-sm"><Switch :model-value="form.plan_for_model" @update:model-value="(v: boolean) => (form.plan_for_model = v)" /> Usar esta planta en todas las unidades del modelo {{ form.model }}</label>
                        <p v-if="form.errors.floor_plan" class="text-xs text-destructive">{{ form.errors.floor_plan }}</p>
                        <p class="text-xs text-muted-foreground">JPG, PNG o WebP (máx. 8 MB). Se muestra en la cotización y en el PDF.</p>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar unidad</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
