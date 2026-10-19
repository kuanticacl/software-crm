<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, Clock, Mail, Pencil, Plus, Trash2, Zap } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { destroy, index, store, update } from '@/routes/automations';

type Auto = {
    id: number; name: string; trigger: string; conditions: { source_ids?: number[]; stage_ids?: number[]; web_only?: boolean; plan_ids?: number[] } | null; offset_days: number | null; subject: string | null;
    to_mode: 'lead' | 'fixed' | 'team'; to_email: string | null; delay_minutes: number; variables: Record<string, string> | null; is_active: boolean;
    runs_count: number; last_run_at: string | null; template_id: number; template: { id: number; name: string; slug: string } | null; sent: number; failed: number;
};
const props = defineProps<{
    automations: Auto[]; triggers: Record<string, string>; billingTriggers: string[]; offsetTriggers: string[]; plans: { id: number; name: string }[];
    templates: { id: number; name: string; slug: string; category: string; variables: { key: string }[] | null }[];
    sources: { id: number; name: string; color: string }[]; stages: { id: number; name: string; color: string }[];
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Automatizaciones', href: index() }] } });

const open = ref(false);
const editing = ref<Auto | null>(null);
const form = useForm({
    name: '', trigger: 'lead.created', conditions: { source_ids: [] as number[], stage_ids: [] as number[], web_only: true, plan_ids: [] as number[] }, offset_days: 0 as number | string,
    template_id: '' as number | string, subject: '', to_mode: 'lead', to_email: '', delay_minutes: 0, variables: [] as { key: string; value: string }[], is_active: true,
});

const openCreate = () => {
    editing.value = null;
    form.defaults({ name: '', trigger: 'lead.created', conditions: { source_ids: [], stage_ids: [], web_only: true, plan_ids: [] }, offset_days: 0, template_id: props.templates[0]?.id ?? '', subject: '', to_mode: 'lead', to_email: '', delay_minutes: 0, variables: [], is_active: true }).reset();
    form.clearErrors();
    open.value = true;
};
const openEdit = (a: Auto) => {
    editing.value = a;
    form.defaults({
        name: a.name, trigger: a.trigger, conditions: { source_ids: [...(a.conditions?.source_ids ?? [])], stage_ids: [...(a.conditions?.stage_ids ?? [])], web_only: !!a.conditions?.web_only, plan_ids: [...(a.conditions?.plan_ids ?? [])] }, offset_days: a.offset_days ?? 0,
        template_id: a.template_id, subject: a.subject ?? '', to_mode: a.to_mode, to_email: a.to_email ?? '', delay_minutes: a.delay_minutes,
        variables: Object.entries(a.variables ?? {}).map(([key, value]) => ({ key, value })), is_active: a.is_active,
    }).reset();
    form.clearErrors();
    open.value = true;
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (open.value = false) };
    editing.value ? form.submit(update(editing.value.id), opts) : form.submit(store(), opts);
};
const toggleIn = (arr: number[], v: number) => { const i = arr.indexOf(v); i >= 0 ? arr.splice(i, 1) : arr.push(v); };
const chip = (on: boolean) => cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition', on ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted');

const toggleActive = (a: Auto) => router.put(update(a.id).url, {
    name: a.name, trigger: a.trigger, conditions: a.conditions, offset_days: a.offset_days, template_id: a.template_id, subject: a.subject, to_mode: a.to_mode, to_email: a.to_email,
    delay_minutes: a.delay_minutes, variables: Object.entries(a.variables ?? {}).map(([key, value]) => ({ key, value })), is_active: !a.is_active,
}, { preserveScroll: true });

const toDelete = ref<Auto | null>(null);
const confirmDelete = () => toDelete.value && router.delete(destroy(toDelete.value.id).url, { preserveScroll: true, onFinish: () => (toDelete.value = null) });

const tplVars = computed(() => props.templates.find((t) => t.id === Number(form.template_id))?.variables?.map((v) => v.key) ?? []);
const names = (ids: number[] | undefined, list: { id: number; name: string }[]) => (ids ?? []).map((id) => list.find((x) => x.id === id)?.name).filter(Boolean).join(', ');
const offsetText = (d: number) => (d === 0 ? 'el mismo día' : d < 0 ? `${-d} ${d === -1 ? 'día' : 'días'} antes` : `${d} ${d === 1 ? 'día' : 'días'} después`);
const isBilling = computed(() => props.billingTriggers.includes(form.trigger));
const delayText = (m: number) => (m === 0 ? 'al instante' : m < 60 ? `${m} min después` : m < 1440 ? `${Math.round(m / 60)} h después` : `${Math.round(m / 1440)} d después`);
</script>

<template>
    <Head title="Automatizaciones" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Automatizaciones de email" description="Reglas que envían una plantilla solas cuando pasa algo con un cliente, por ejemplo: «cuando alguien se registra en la landing, enviar el correo de bienvenida».">
            <template #actions><Button @click="openCreate"><Plus /> Nueva automatización</Button></template>
        </PageHeader>

        <p v-if="!templates.length" class="rounded-xl border border-[#FFA165]/50 bg-[#FFA165]/10 p-4 text-sm text-[#7A3A00]">Primero crea una plantilla en <Link href="/email/templates" class="font-semibold underline">Plantillas</Link>.</p>
        <p v-else-if="!automations.length" class="rounded-2xl border border-dashed bg-card py-14 text-center text-sm text-muted-foreground"><Zap class="mx-auto mb-2 size-7" />Aún no tienes automatizaciones.</p>

        <div class="grid gap-4 lg:grid-cols-2">
            <article v-for="a in automations" :key="a.id" class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]" :class="!a.is_active && 'opacity-60'">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-accent-foreground"><Zap class="size-5" /></div>
                        <div class="min-w-0"><h3 class="truncate font-semibold">{{ a.name }}</h3><p class="text-xs text-muted-foreground">{{ triggers[a.trigger] }}</p></div>
                    </div>
                    <Switch :model-value="a.is_active" @update:model-value="toggleActive(a)" />
                </div>

                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                    <Badge variant="outline">{{ triggers[a.trigger] }}<template v-if="offsetTriggers.includes(a.trigger)"> · {{ offsetText(a.offset_days ?? 0) }}</template></Badge>
                    <Badge v-if="a.conditions?.web_only && a.trigger === 'lead.created'" variant="secondary">desde la web</Badge>
                    <template v-if="a.conditions?.source_ids?.length"><span class="text-muted-foreground">de</span><Badge variant="secondary">{{ names(a.conditions.source_ids, sources) }}</Badge></template>
                    <template v-if="a.conditions?.stage_ids?.length"><span class="text-muted-foreground">a</span><Badge variant="secondary">{{ names(a.conditions.stage_ids, stages) }}</Badge></template>
                    <ArrowRight class="size-3.5 text-muted-foreground" />
                    <Badge class="border-transparent bg-primary/10 text-primary"><Mail /> {{ a.template?.name ?? 'Plantilla eliminada' }}</Badge>
                </div>
                <p class="flex items-center gap-3 text-xs text-muted-foreground">
                    <span class="flex items-center gap-1"><Clock class="size-3.5" />{{ delayText(a.delay_minutes) }}</span>
                    <span>a {{ a.to_mode === 'lead' ? 'el correo del cliente' : a.to_mode === 'team' ? 'el equipo (responsable asignado y del origen)' : a.to_email }}</span>
                </p>

                <div class="mt-auto flex items-center justify-between border-t pt-3 text-xs text-muted-foreground">
                    <span>{{ a.runs_count }} ejecuciones · {{ a.sent }} enviados<template v-if="a.failed"> · <span class="text-destructive">{{ a.failed }} con error</span></template><template v-if="a.last_run_at"> · última {{ timeAgo(a.last_run_at) }}</template></span>
                    <span class="flex"><Button variant="ghost" size="icon-sm" title="Editar" @click="openEdit(a)"><Pencil /></Button><Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" @click="toDelete = a"><Trash2 /></Button></span>
                </div>
            </article>
        </div>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader><DialogTitle>{{ editing ? 'Editar automatización' : 'Nueva automatización' }}</DialogTitle><DialogDescription>Define cuándo se dispara y qué plantilla se envía. Los datos del cliente (nombre, empresa, UTM, campos personalizados y cualquier parámetro enviado por la API) quedan disponibles como variables.</DialogDescription></DialogHeader>
            <form class="grid gap-5" @submit.prevent="save">
                <FormField label="Nombre" for="an" :error="form.errors.name" required><Input id="an" v-model="form.name" placeholder="Ej: Bienvenida landing Proyecto Norte" /></FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Cuándo" for="at" :error="form.errors.trigger"><NativeSelect id="at" v-model="form.trigger"><option v-for="(l, k) in triggers" :key="k" :value="k">{{ l }}</option></NativeSelect></FormField>
                    <FormField label="Enviar con un retraso de (minutos)" for="ad" hint="0 = al instante · 60 = 1 hora · 1440 = 1 día" :error="form.errors.delay_minutes"><Input id="ad" v-model.number="form.delay_minutes" type="number" min="0" /></FormField>
                </div>

                <FormField v-if="offsetTriggers.includes(form.trigger)" label="Cuántos días" for="ao" :error="form.errors.offset_days" :hint="form.trigger === 'invoice.due' ? 'Respecto del vencimiento del cobro: -5 = 5 días antes · 0 = el día del vencimiento · 3 = 3 días después.' : 'Respecto del término o renovación del período: -10 = 10 días antes.'"><Input id="ao" v-model.number="form.offset_days" type="number" min="-90" max="365" /></FormField>

                <div class="grid gap-3 rounded-xl border p-4">
                    <p class="text-sm font-medium">Condiciones <span class="text-xs font-normal text-muted-foreground">(sin marcar = cualquiera)</span></p>
                    <div v-if="isBilling"><p class="mb-1.5 text-xs text-muted-foreground">Plan de la suscripción</p><div class="flex flex-wrap gap-2"><button v-for="p in plans" :key="p.id" type="button" :class="chip(form.conditions.plan_ids.includes(p.id))" @click="toggleIn(form.conditions.plan_ids, p.id)">{{ p.name }}</button></div></div>
                    <div v-if="!isBilling"><p class="mb-1.5 text-xs text-muted-foreground">Origen del cliente</p><div class="flex flex-wrap gap-2"><button v-for="s in sources" :key="s.id" type="button" :class="chip(form.conditions.source_ids.includes(s.id))" @click="toggleIn(form.conditions.source_ids, s.id)"><span class="size-2 rounded-full" :style="{ backgroundColor: s.color }" />{{ s.name }}</button></div></div>
                    <label v-if="form.trigger === 'lead.created'" class="flex items-start justify-between gap-4 text-sm"><span><span class="font-medium">Solo registros de la web</span><br /><span class="text-xs text-muted-foreground">Clientes que llegan por formularios o la API (no los que ingresas a mano).</span></span><Switch :model-value="form.conditions.web_only" @update:model-value="(v: boolean) => (form.conditions.web_only = v)" /></label>
                    <div v-if="form.trigger === 'lead.stage_changed'"><p class="mb-1.5 text-xs text-muted-foreground">Pasa a la etapa</p><div class="flex flex-wrap gap-2"><button v-for="s in stages" :key="s.id" type="button" :class="chip(form.conditions.stage_ids.includes(s.id))" @click="toggleIn(form.conditions.stage_ids, s.id)"><span class="size-2 rounded-full" :style="{ backgroundColor: s.color }" />{{ s.name }}</button></div></div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Plantilla" for="atp" :error="form.errors.template_id" required><NativeSelect id="atp" v-model="form.template_id"><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option></NativeSelect></FormField>
                    <FormField label="Asunto (opcional)" for="as" hint="Reemplaza el de la plantilla. Admite variables."><Input id="as" v-model="form.subject" /></FormField>
                    <FormField label="Enviar a" for="am"><NativeSelect id="am" v-model="form.to_mode"><option value="lead">{{ isBilling ? 'El correo de la empresa cliente' : 'El correo del cliente' }}</option><option value="fixed">Una dirección fija (aviso interno)</option><option v-if="!isBilling" value="team">El equipo: responsable asignado, responsables del origen y correos por defecto</option></NativeSelect></FormField>
                    <FormField v-if="form.to_mode === 'fixed'" label="Correo de aviso" for="ae" :error="form.errors.to_email"><Input id="ae" v-model="form.to_email" type="email" placeholder="ventas@mecaniq.cl" /></FormField>
                </div>

                <div class="grid gap-2 rounded-xl border p-4">
                    <div class="flex items-center justify-between"><p class="text-sm font-medium">Valores fijos de variables</p><Button type="button" variant="ghost" size="sm" @click="form.variables.push({ key: '', value: '' })"><Plus /> Agregar</Button></div>
                    <p class="text-xs text-muted-foreground">Sirven como valor por defecto (p. ej. <code>proyecto</code> = «Torre Norte»); si el cliente trae ese dato, prevalece el del cliente.<template v-if="tplVars.length"> Variables de la plantilla: <code v-for="v in tplVars" :key="v" class="mr-1">{{ v }}</code></template></p>
                    <div v-for="(v, i) in form.variables" :key="i" class="flex gap-2"><Input v-model="v.key" placeholder="variable" class="w-40 font-mono text-xs" /><Input v-model="v.value" placeholder="valor" /><Button type="button" variant="ghost" size="icon-sm" @click="form.variables.splice(i, 1)"><Trash2 /></Button></div>
                </div>

                <label class="flex items-center gap-3 text-sm"><Switch :model-value="form.is_active" @update:model-value="(v: boolean) => (form.is_active = v)" /> Activa</label>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="open = false">Cancelar</Button><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toDelete" title="Eliminar automatización" :description="`Se eliminará «${toDelete?.name}». Los correos ya enviados se conservan en el historial.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="confirmDelete" />
</template>
