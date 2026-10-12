<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Package, Pencil, Plus, Repeat, Search, Sparkles, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { HttpError, sendJson } from '@/lib/http';
import { stripHtml } from '@/lib/richText';
import { formatAmount, formatMoney, formatUfValue } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { destroy, index, store, update } from '@/routes/services';

type Service = { id: number; name: string; category: string; description: string | null; deliverables: string[] | null; billing: 'one_time' | 'monthly'; unit: string; currency: 'UF' | 'CLP'; price: number; is_active: boolean };

const props = defineProps<{ services: Service[]; categories: string[]; uf: { value: number; date: string } | null; ai: { enabled: boolean }; can: { manage: boolean } }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Catálogo de servicios', href: index() }] } });

const q = ref('');
const billing = ref('');
const grouped = computed(() => {
    const term = q.value.trim().toLowerCase();
    const list = props.services.filter((s) => (!billing.value || s.billing === billing.value) && (!term || `${s.name} ${s.category} ${s.description ?? ''}`.toLowerCase().includes(term)));
    const map = new Map<string, Service[]>();
    list.forEach((s) => map.set(s.category, [...(map.get(s.category) ?? []), s]));
    return [...map.entries()];
});

const open = ref(false);
const editing = ref<Service | null>(null);
const deliverablesText = ref('');
const form = useForm({ name: '', category: '', description: '', deliverables: [] as string[], billing: 'one_time', unit: 'servicio', currency: 'UF', price: 0, is_active: true });

const openCreate = () => {
    editing.value = null;
    form.defaults({ name: '', category: props.categories[0] ?? 'General', description: '', deliverables: [], billing: 'one_time', unit: 'servicio', currency: 'UF', price: 0, is_active: true }).reset();
    form.clearErrors();
    deliverablesText.value = '';
    open.value = true;
};
const openEdit = (s: Service) => {
    editing.value = s;
    form.defaults({ name: s.name, category: s.category, description: s.description ?? '', deliverables: s.deliverables ?? [], billing: s.billing, unit: s.unit, currency: s.currency, price: s.price, is_active: s.is_active }).reset();
    form.clearErrors();
    deliverablesText.value = (s.deliverables ?? []).join('\n');
    open.value = true;
};
const save = () => {
    form.deliverables = deliverablesText.value.split('\n').map((x) => x.trim()).filter(Boolean);
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.submit(update(editing.value.id), opts) : form.submit(store(), opts);
};
const onBilling = () => {
    if (form.unit === 'servicio' || form.unit === 'mes') form.unit = form.billing === 'monthly' ? 'mes' : 'servicio';
};

const toDelete = ref<Service | null>(null);
const remove = () => toDelete.value && router.delete(destroy(toDelete.value.id).url, { preserveScroll: true, onFinish: () => (toDelete.value = null) });

// ---- IA: redacta descripción y entregables
const aiBusy = ref(false);
const aiWrite = async () => {
    if (!form.name.trim()) return toast.info('Primero escribe el nombre del servicio.');
    aiBusy.value = true;
    try {
        const r = await sendJson<{ description: string; deliverables: string[] }>('POST', '/proposals/ai/service', { name: form.name, category: form.category, billing: form.billing, hint: form.description });
        form.description = r.description;
        deliverablesText.value = r.deliverables.join('\n');
        toast.success('Descripción generada. Revísala antes de guardar.');
    } catch (e) {
        toast.error(e instanceof HttpError ? (e.body?.message ?? 'No se pudo generar.') : 'No se pudo generar.');
    } finally {
        aiBusy.value = false;
    }
};
</script>

<template>
    <Head title="Catálogo de servicios" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Catálogo de servicios" description="Catálogo de servicios pre armados de la agencia. Al armar una propuesta puedes usarlos tal cual o crear servicios únicos para ese cliente.">
            <template #actions>
                <Button v-if="can.manage" @click="openCreate"><Plus /> Nuevo servicio</Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative w-full max-w-xs">
                <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="q" placeholder="Buscar servicio…" class="pl-9" />
            </div>
            <NativeSelect v-model="billing" class="w-44"><option value="">Todas las modalidades</option><option value="one_time">Pago único</option><option value="monthly">Mensual</option></NativeSelect>
        </div>

        <p v-if="!grouped.length" class="rounded-2xl border border-dashed bg-card py-12 text-center text-sm text-muted-foreground">No hay servicios que coincidan.</p>

        <section v-for="[cat, list] in grouped" :key="cat" class="flex flex-col gap-3">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-muted-foreground"><Package class="size-4 text-primary" /> {{ cat }} <span class="rounded-full bg-muted px-2 text-xs">{{ list.length }}</span></h2>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="s in list" :key="s.id" :class="cn('flex flex-col gap-2 rounded-2xl border bg-card p-4 shadow-sm shadow-black/[0.03]', !s.is_active && 'opacity-60')">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-semibold leading-tight">{{ s.name }}</h3>
                        <Badge :class="s.billing === 'monthly' ? 'border-transparent bg-[#121826]/10 text-[#121826]' : 'border-transparent bg-primary/10 text-primary'">
                            <Repeat v-if="s.billing === 'monthly'" class="size-3" />{{ s.billing === 'monthly' ? 'Mensual' : 'Pago único' }}
                        </Badge>
                    </div>
                    <p v-if="s.description" class="line-clamp-3 text-sm text-muted-foreground">{{ stripHtml(s.description) }}</p>
                    <ul v-if="s.deliverables?.length" class="grid gap-0.5 text-xs text-muted-foreground">
                        <li v-for="d in s.deliverables.slice(0, 3)" :key="d" class="flex gap-1.5"><span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary" />{{ d }}</li>
                        <li v-if="s.deliverables.length > 3" class="pl-3">+{{ s.deliverables.length - 3 }} más</li>
                    </ul>
                    <div class="mt-auto flex items-end justify-between pt-2">
                        <p><span class="text-lg font-bold text-primary">{{ formatAmount(s.price, s.currency) }}</span><span class="text-xs text-muted-foreground"> neto / {{ s.unit }}</span><span v-if="s.currency === 'UF' && uf" class="block text-[11px] text-muted-foreground">≈ {{ formatMoney(Math.round(s.price * uf.value)) }}</span></p>
                        <div v-if="can.manage" class="flex gap-1">
                            <Button variant="ghost" size="icon-sm" title="Editar" @click="openEdit(s)"><Pencil /></Button>
                            <Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" @click="toDelete = s"><Trash2 /></Button>
                        </div>
                    </div>
                    <p v-if="!s.is_active" class="text-[11px] text-muted-foreground">Inactivo: no aparece al armar propuestas.</p>
                </article>
            </div>
        </section>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ editing ? 'Editar servicio' : 'Nuevo servicio' }}</DialogTitle>
                <DialogDescription>La tarifa es la de referencia (honorarios netos; la propuesta suma la retención de la boleta). En cada propuesta se puede ajustar sin afectar el catálogo.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <FormField label="Nombre del servicio" for="sv-name" :error="form.errors.name" required><Input id="sv-name" v-model="form.name" placeholder="Gestión de campañas Meta Ads" /></FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Categoría" for="sv-cat" :error="form.errors.category" required>
                        <Input id="sv-cat" v-model="form.category" list="sv-cats" placeholder="Marketing digital" /><datalist id="sv-cats"><option v-for="c in categories" :key="c" :value="c" /></datalist>
                    </FormField>
                    <FormField label="Modalidad de cobro" :error="form.errors.billing">
                        <NativeSelect v-model="form.billing" @update:model-value="onBilling"><option value="one_time">Pago único</option><option value="monthly">Mensual (recurrente)</option></NativeSelect>
                    </FormField>
                    <FormField label="Tarifa neta" for="sv-price" :error="form.errors.price" required :hint="form.currency === 'UF' && uf ? `UF de hoy: ${formatUfValue(uf.value)}` : undefined">
                        <div class="flex gap-2"><NativeSelect v-model="form.currency" class="w-24"><option value="UF">UF</option><option value="CLP">CLP</option></NativeSelect><Input id="sv-price" v-model.number="form.price" type="number" min="0" :step="form.currency === 'UF' ? 0.5 : 1000" /></div>
                    </FormField>
                    <FormField label="Unidad" for="sv-unit" :error="form.errors.unit"><Input id="sv-unit" v-model="form.unit" placeholder="mes, proyecto, hora…" /></FormField>
                </div>
                <FormField label="Descripción" for="sv-desc" :error="form.errors.description">
                    <RichTextEditor v-model="form.description" :min-height="90" placeholder="Qué incluye y qué problema resuelve" />
                    <Button v-if="ai.enabled" type="button" variant="outline" size="sm" class="mt-2 w-fit border-primary/40 text-primary" :disabled="aiBusy" @click="aiWrite"><Spinner v-if="aiBusy" /><Sparkles v-else /> Redactar con IA</Button>
                </FormField>
                <FormField label="Entregables" for="sv-del" hint="Uno por línea. Aparecen como viñetas en la propuesta."><Textarea id="sv-del" v-model="deliverablesText" rows="4" placeholder="Estrategia y segmentación&#10;Informe mensual" /></FormField>
                <label class="flex items-center gap-3 text-sm"><Switch :model-value="form.is_active" @update:model-value="(v: boolean) => (form.is_active = v)" /> Activo (disponible al armar propuestas)</label>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toDelete" title="¿Eliminar este servicio?" :description="`«${toDelete?.name}» saldrá del catálogo. Las propuestas ya creadas conservan su copia.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="remove" />
</template>
