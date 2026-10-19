<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Gauge, Infinity as InfinityIcon, Pencil, Plus, Settings2, Trash2, Users } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from '@/lib/leadUi';
import { destroy, index, store, update } from '@/routes/billing/plans';
import { destroy as destroyLimit, store as storeLimit, update as updateLimit } from '@/routes/billing/limits';

type Period = 'monthly' | 'quarterly' | 'annual';
type Plan = {
    id: number; name: string; slug: string; tagline: string | null; description: string | null; features: string[]; is_active: boolean; is_public: boolean; active_subscriptions: number;
    prices: Partial<Record<Period, { amount: number; is_active: boolean }>>; limits: Record<number, number | null>;
};
type LimitType = { id: number; key: string; name: string; unit: string | null; description: string | null };

const props = defineProps<{ plans: Plan[]; limitTypes: LimitType[]; periods: Record<Period, string> }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Planes y límites', href: index() }] } });

const periodKeys = Object.keys(props.periods) as Period[];
const num = (v: unknown) => (v === null || v === undefined || v === '' ? '' : String(v));
const fmt = new Intl.NumberFormat('es-CL');

// ------------------------------------------------------------ plan
const open = ref(false);
const editing = ref<Plan | null>(null);
const featuresText = ref('');
const form = useForm({
    name: '', tagline: '', description: '', features: [] as string[], is_active: true, is_public: true,
    prices: {} as Record<string, { amount: string; is_active: boolean }>,
    limits: {} as Record<string, string>,
});
const fill = (p: Plan | null) => {
    form.defaults({
        name: p?.name ?? '', tagline: p?.tagline ?? '', description: p?.description ?? '', features: p?.features ?? [], is_active: p?.is_active ?? true, is_public: p?.is_public ?? true,
        prices: Object.fromEntries(periodKeys.map((k) => [k, { amount: num(p?.prices[k]?.amount), is_active: p?.prices[k]?.is_active ?? true }])),
        limits: Object.fromEntries(props.limitTypes.map((l) => [String(l.id), num(p?.limits[l.id])])),
    }).reset();
    form.clearErrors();
    featuresText.value = (p?.features ?? []).join('\n');
};
const openCreate = () => { editing.value = null; fill(null); open.value = true; };
const openEdit = (p: Plan) => { editing.value = p; fill(p); open.value = true; };
const save = () => {
    form.features = featuresText.value.split('\n').map((x) => x.trim()).filter(Boolean);
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.submit(update(editing.value.id), opts) : form.submit(store(), opts);
};
const toDelete = ref<Plan | null>(null);
const confirmDelete = () => toDelete.value && router.delete(destroy(toDelete.value.id).url, { preserveScroll: true, onFinish: () => (toDelete.value = null) });

// ------------------------------------------------------------ tipos de límite
const limitsOpen = ref(false);
const limitForm = useForm({ name: '', unit: '', description: '' });
const editingLimit = ref<LimitType | null>(null);
const saveLimit = () => {
    const opts = { preserveScroll: true, onSuccess: () => { limitForm.reset(); editingLimit.value = null; } };
    editingLimit.value ? limitForm.submit(updateLimit(editingLimit.value.id), opts) : limitForm.submit(storeLimit(), opts);
};
const editLimit = (l: LimitType) => { editingLimit.value = l; limitForm.defaults({ name: l.name, unit: l.unit ?? '', description: l.description ?? '' }).reset(); };
const delLimit = (l: LimitType) => confirm(`¿Eliminar el límite «${l.name}» de todos los planes?`) && router.delete(destroyLimit(l.id).url, { preserveScroll: true });

const limitText = (v: number | null | undefined) => (v === null || v === undefined ? null : fmt.format(v));
</script>

<template>
    <Head title="Planes y límites" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Planes y límites" description="Crea los planes que vendes por suscripción, con su precio mensual, trimestral y anual y los límites de uso de cada uno. Se eligen en las propuestas.">
            <template #actions>
                <Button variant="outline" @click="limitsOpen = true"><Gauge /> Tipos de límite</Button>
                <Button @click="openCreate"><Plus /> Nuevo plan</Button>
            </template>
        </PageHeader>

        <p v-if="!plans.length" class="rounded-2xl border border-dashed bg-card py-14 text-center text-sm text-muted-foreground">Aún no hay planes. Crea el primero para poder cotizar suscripciones.</p>

        <div class="grid gap-4 lg:grid-cols-3">
            <article v-for="p in plans" :key="p.id" class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]" :class="!p.is_active && 'opacity-60'">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold">{{ p.name }}</h3>
                        <p class="text-sm text-muted-foreground">{{ p.tagline || '—' }}</p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Badge v-if="!p.is_active" variant="secondary">Inactivo</Badge>
                        <Badge v-else-if="!p.is_public" variant="outline">Privado</Badge>
                    </div>
                </div>

                <div class="grid gap-1.5 rounded-xl bg-muted/50 p-3 text-sm">
                    <div v-for="k in periodKeys" :key="k" class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ periods[k] }}</span>
                        <span v-if="p.prices[k]" class="font-semibold" :class="!p.prices[k]!.is_active && 'text-muted-foreground line-through'">{{ formatMoney(p.prices[k]!.amount) || '$0' }} <span class="text-xs font-normal text-muted-foreground">+ IVA</span></span>
                        <span v-else class="text-xs text-muted-foreground">No disponible</span>
                    </div>
                </div>

                <ul class="grid gap-1.5 text-sm">
                    <li v-for="l in limitTypes.filter((x) => x.id in p.limits)" :key="l.id" class="flex items-center justify-between gap-2">
                        <span class="text-muted-foreground">{{ l.name }}</span>
                        <span class="flex items-center gap-1 font-medium"><InfinityIcon v-if="p.limits[l.id] === null" class="size-4" /><template v-else>{{ limitText(p.limits[l.id]) }}</template><span v-if="p.limits[l.id] !== null && l.unit" class="text-xs font-normal text-muted-foreground">{{ l.unit }}</span></span>
                    </li>
                </ul>
                <ul v-if="p.features.length" class="grid gap-1 border-t pt-3 text-xs text-muted-foreground"><li v-for="f in p.features" :key="f">✓ {{ f }}</li></ul>

                <div class="mt-auto flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                    <span class="flex items-center gap-1"><Users class="size-3.5" />{{ p.active_subscriptions }} suscripciones vigentes</span>
                    <span class="flex">
                        <Button variant="ghost" size="icon-sm" title="Editar" @click="openEdit(p)"><Pencil /></Button>
                        <Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" @click="toDelete = p"><Trash2 /></Button>
                    </span>
                </div>
            </article>
        </div>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-3xl">
            <DialogHeader><DialogTitle>{{ editing ? `Editar plan ${editing.name}` : 'Nuevo plan' }}</DialogTitle><DialogDescription>Precios netos en pesos por período (se agrega IVA al cobrar). Deja un precio vacío si ese período no se vende. Un límite vacío significa ilimitado.</DialogDescription></DialogHeader>
            <form class="grid gap-5" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Nombre" for="pn" :error="form.errors.name" required><Input id="pn" v-model="form.name" placeholder="Pro" /></FormField>
                    <FormField label="Frase corta" for="pt" :error="form.errors.tagline"><Input id="pt" v-model="form.tagline" placeholder="Para talleres en crecimiento" /></FormField>
                </div>

                <div class="grid gap-3 rounded-xl border p-4">
                    <p class="text-sm font-medium">Precios (neto, CLP)</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div v-for="k in periodKeys" :key="k" class="grid gap-1.5">
                            <label class="text-xs text-muted-foreground">{{ periods[k] }}</label>
                            <Input v-model="form.prices[k].amount" type="number" min="0" inputmode="numeric" placeholder="No se vende" />
                            <label class="flex items-center gap-2 text-xs text-muted-foreground"><Switch :model-value="form.prices[k].is_active" @update:model-value="(v: boolean) => (form.prices[k].is_active = v)" /> Disponible</label>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 rounded-xl border p-4">
                    <div class="flex items-center justify-between"><p class="text-sm font-medium">Límites del plan</p><Button type="button" variant="ghost" size="sm" @click="limitsOpen = true"><Settings2 /> Administrar tipos</Button></div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-for="l in limitTypes" :key="l.id" class="grid gap-1.5">
                            <label class="text-xs text-muted-foreground">{{ l.name }}<template v-if="l.unit"> ({{ l.unit }})</template></label>
                            <Input v-model="form.limits[String(l.id)]" type="number" min="0" inputmode="numeric" placeholder="Ilimitado" />
                        </div>
                    </div>
                    <p v-if="!limitTypes.length" class="text-xs text-muted-foreground">Aún no hay tipos de límite. Créalos en «Administrar tipos».</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Características (una por línea)" for="pf"><Textarea id="pf" v-model="featuresText" rows="5" placeholder="Inventario de repuestos&#10;Reportes de gestión" /></FormField>
                    <FormField label="Descripción" for="pd" :error="form.errors.description"><Textarea id="pd" v-model="form.description" rows="5" /></FormField>
                </div>

                <div class="flex flex-wrap gap-6">
                    <label class="flex items-center gap-3 text-sm"><Switch :model-value="form.is_active" @update:model-value="(v: boolean) => (form.is_active = v)" /> Activo (se puede cotizar)</label>
                    <label class="flex items-center gap-3 text-sm"><Switch :model-value="form.is_public" @update:model-value="(v: boolean) => (form.is_public = v)" /> Público (aparece en la API de planes)</label>
                </div>

                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="open = false">Cancelar</Button><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar plan</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="limitsOpen">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader><DialogTitle>Tipos de límite</DialogTitle><DialogDescription>Cada tipo aparece en todos los planes para fijar su valor. La app lo recibe por la API con su clave (key).</DialogDescription></DialogHeader>
            <ul class="grid gap-2">
                <li v-for="l in limitTypes" :key="l.id" class="flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-sm">
                    <span class="min-w-0"><span class="font-medium">{{ l.name }}</span><span v-if="l.unit" class="text-muted-foreground"> · {{ l.unit }}</span><br /><code class="text-[11px] text-muted-foreground">{{ l.key }}</code></span>
                    <span class="flex"><Button variant="ghost" size="icon-sm" @click="editLimit(l)"><Pencil /></Button><Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" @click="delLimit(l)"><Trash2 /></Button></span>
                </li>
            </ul>
            <form class="grid gap-3 rounded-xl border p-4" @submit.prevent="saveLimit">
                <p class="text-sm font-medium">{{ editingLimit ? `Editar «${editingLimit.name}»` : 'Nuevo tipo de límite' }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Nombre" for="ln" :error="limitForm.errors.name"><Input id="ln" v-model="limitForm.name" placeholder="Órdenes de trabajo al mes" /></FormField>
                    <FormField label="Unidad" for="lu"><Input id="lu" v-model="limitForm.unit" placeholder="órdenes" /></FormField>
                </div>
                <FormField label="Descripción" for="ld"><Input id="ld" v-model="limitForm.description" /></FormField>
                <div class="flex justify-end gap-2"><Button v-if="editingLimit" type="button" variant="outline" @click="editingLimit = null; limitForm.reset()">Cancelar</Button><Button type="submit" :disabled="limitForm.processing"><Spinner v-if="limitForm.processing" /> {{ editingLimit ? 'Guardar' : 'Agregar' }}</Button></div>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toDelete" title="Eliminar plan" :description="`Se eliminará «${toDelete?.name}». Las propuestas y suscripciones existentes conservan su plan.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="confirmDelete" />
</template>
