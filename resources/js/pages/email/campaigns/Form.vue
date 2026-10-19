<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarClock, Eye, Save, Send, Users } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PreviewFrame from '@/components/email/PreviewFrame.vue';
import VariableField from '@/components/email/VariableField.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import TagInput from '@/components/TagInput.vue';
import DataCard from '@/components/DataCard.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { HttpError, sendJson } from '@/lib/http';
import { priorityMeta } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { audienceCount, index, preview, send, store, test, update } from '@/routes/campaigns';

type Audience = {
    lists: number[];
    leads: { enabled: boolean; sources: number[]; stages: number[]; assignees: number[]; priorities: string[]; tags: string[]; created_from: string; created_to: string };
    clients: { enabled: boolean; only_active: boolean };
};

const props = defineProps<{
    campaign: { id: number; name: string; subject: string; preheader: string | null; template_id: number | null; from_name: string | null; from_email: string | null; reply_to: string | null; audience: Partial<Audience> | null; track_opens: boolean; track_clicks: boolean; scheduled_at: string | null; status: string } | null;
    templates: { id: number; name: string; subject: string; preheader: string | null; category: string }[];
    lists: { id: number; name: string; entries_count: number }[];
    sources: { id: number; name: string; color: string }[];
    stages: { id: number; name: string; color: string }[];
    users: { id: number; name: string }[];
    priorities: Record<string, string>;
    defaults: { from_name: string; from_email: string | null; reply_to: string | null };
    canSend: boolean;
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Boletines', href: index() }] } });

const c = props.campaign;
const a = c?.audience ?? {};
const form = reactive({
    name: c?.name ?? '',
    subject: c?.subject ?? '',
    preheader: c?.preheader ?? '',
    template_id: (c?.template_id ?? '') as number | string,
    from_name: c?.from_name ?? '',
    from_email: c?.from_email ?? '',
    reply_to: c?.reply_to ?? '',
    track_opens: c?.track_opens ?? true,
    track_clicks: c?.track_clicks ?? true,
});
const audience = reactive<Audience>({
    lists: [...(a.lists ?? [])],
    leads: { enabled: false, sources: [], stages: [], assignees: [], priorities: [], tags: [], created_from: '', created_to: '', ...(a.leads ?? {}) },
    clients: { enabled: false, only_active: true, ...(a.clients ?? {}) },
});
const mode = ref<'now' | 'schedule'>(c?.scheduled_at ? 'schedule' : 'now');
const scheduledAt = ref(c?.scheduled_at ?? '');

const campaignId = ref<number | null>(c?.id ?? null);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

const toggle = (arr: (number | string)[], v: number | string) => {
    const i = arr.indexOf(v);
    i >= 0 ? arr.splice(i, 1) : arr.push(v);
};

// ---- contador de audiencia en vivo ----
const count = ref<number | null>(null);
const counting = ref(false);
let countTimer: ReturnType<typeof setTimeout>;
const refreshCount = () => {
    clearTimeout(countTimer);
    counting.value = true;
    countTimer = setTimeout(async () => {
        try {
            count.value = (await sendJson<{ count: number }>('POST', audienceCount().url, { audience: JSON.parse(JSON.stringify(audience)) })).count;
        } finally {
            counting.value = false;
        }
    }, 500);
};
watch(audience, refreshCount, { deep: true, immediate: true });
onBeforeUnmount(() => clearTimeout(countTimer));

// ---- vista previa ----
const html = ref('');
const renderedSubject = ref('');
let pTimer: ReturnType<typeof setTimeout>;
watch([() => form.template_id, () => form.subject, () => form.preheader], () => {
    clearTimeout(pTimer);
    if (!form.template_id) { html.value = ''; return; }
    pTimer = setTimeout(async () => {
        try {
            const r = await sendJson<{ html: string; subject: string }>('POST', preview().url, { template_id: form.template_id, subject: form.subject, preheader: form.preheader });
            html.value = r.html;
            renderedSubject.value = r.subject;
        } catch { /* se reintenta con el próximo cambio */ }
    }, 400);
}, { immediate: true });
const pickTemplate = () => {
    const t = props.templates.find((x) => x.id === Number(form.template_id));
    if (t) {
        if (!form.subject) form.subject = t.subject;
        if (!form.preheader) form.preheader = t.preheader ?? '';
    }
};

// ---- guardar / enviar ----
const payload = () => ({
    ...form,
    template_id: form.template_id || null,
    from_name: form.from_name || null, from_email: form.from_email || null, reply_to: form.reply_to || null,
    audience: JSON.parse(JSON.stringify(audience)),
    scheduled_at: mode.value === 'schedule' ? scheduledAt.value || null : null,
});
const save = async (): Promise<number | null> => {
    saving.value = true;
    errors.value = {};
    try {
        const res = campaignId.value
            ? await sendJson<{ id: number }>('PUT', update(campaignId.value).url, payload())
            : await sendJson<{ id: number }>('POST', store().url, payload());
        campaignId.value = res.id;
        return res.id;
    } catch (e) {
        errors.value = e instanceof HttpError ? e.fieldErrors : {};
        toast.error(Object.values(errors.value)[0] ?? 'No se pudo guardar el boletín.');
        return null;
    } finally {
        saving.value = false;
    }
};
const saveDraft = async () => {
    const id = await save();
    if (id) {
        toast.success('Borrador guardado');
        if (!props.campaign) router.visit(`/email/campaigns/${id}/edit`, { replace: true });
    }
};

const confirmOpen = ref(false);
const sending = ref(false);
const doSend = async () => {
    sending.value = true;
    const id = await save();
    if (!id) { sending.value = false; confirmOpen.value = false; return; }
    router.post(send(id).url, { when: mode.value, scheduled_at: scheduledAt.value || null }, { onFinish: () => { sending.value = false; confirmOpen.value = false; } });
};

const testOpen = ref(false);
const testTo = ref('');
const testBusy = ref(false);
const testMsg = ref<{ ok: boolean; text: string } | null>(null);
const sendTest = async () => {
    testBusy.value = true;
    testMsg.value = null;
    try {
        const r = await sendJson<{ provider: string }>('POST', test().url, { template_id: form.template_id, to: testTo.value, subject: form.subject, preheader: form.preheader });
        testMsg.value = { ok: true, text: r.provider === 'log' ? 'Modo de prueba: no se envió realmente (sin API key).' : 'Prueba enviada. Revisa tu bandeja.' };
    } catch (e) {
        const b = e instanceof HttpError ? (e.body as { error?: string } | null) : null;
        testMsg.value = { ok: false, text: b?.error ?? 'No se pudo enviar.' };
    } finally {
        testBusy.value = false;
    }
};

const ready = computed(() => form.name && form.subject && form.template_id && (count.value ?? 0) > 0 && (mode.value === 'now' || scheduledAt.value));
const chip = (on: boolean) => cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition', on ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted');
</script>

<template>
    <Head :title="campaign ? 'Editar boletín' : 'Nuevo boletín'" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="campaign ? `Editar: ${campaign.name}` : 'Nuevo boletín'" description="Define el contenido, a quién se envía y cuándo.">
            <template #actions>
                <Button variant="outline" as-child><Link :href="index()">Cancelar</Link></Button>
                <Button variant="outline" :disabled="saving || !form.name || !form.subject || !form.template_id" @click="saveDraft"><Spinner v-if="saving" /><Save v-else /> Guardar borrador</Button>
                <Button v-if="canSend" :disabled="!ready" @click="confirmOpen = true"><component :is="mode === 'now' ? Send : CalendarClock" /> {{ mode === 'now' ? 'Enviar ahora' : 'Programar' }}</Button>
            </template>
        </PageHeader>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
            <div class="flex min-w-0 flex-col gap-5">
                <DataCard class="p-6">
                    <h2 class="mb-4 font-semibold">1 · Contenido</h2>
                    <div class="grid gap-5">
                        <FormField label="Nombre interno" for="cn" :error="errors.name" required><Input id="cn" v-model="form.name" placeholder="Ej: Boletín octubre 2026" /></FormField>
                        <FormField label="Plantilla" for="ct" :error="errors.template_id" required hint="El contenido se congela al momento de enviar.">
                            <NativeSelect id="ct" v-model="form.template_id" @update:model-value="pickTemplate"><option value="">Selecciona una plantilla…</option><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}{{ t.category === 'marketing' ? '' : ' (transaccional)' }}</option></NativeSelect>
                        </FormField>
                        <FormField label="Asunto" for="cs" :error="errors.subject" required><VariableField id="cs" v-model="form.subject" :system="['first_name', 'last_name', 'company', 'to_name']" placeholder="Ej: {{ first_name | default:'Hola' }}, novedades de octubre" /></FormField>
                        <FormField label="Preheader" for="cp" hint="Texto que se ve junto al asunto en la bandeja de entrada."><VariableField id="cp" v-model="form.preheader" :system="['first_name', 'company']" /></FormField>
                        <details class="rounded-xl border p-3 text-sm">
                            <summary class="cursor-pointer font-medium">Remitente (opcional)</summary>
                            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                                <FormField label="Nombre" for="fnm"><Input id="fnm" v-model="form.from_name" :placeholder="defaults.from_name" /></FormField>
                                <FormField label="Correo" for="fem" :error="errors.from_email"><Input id="fem" v-model="form.from_email" type="email" :placeholder="defaults.from_email ?? 'Configuración'" /></FormField>
                                <FormField label="Responder a" for="frt" :error="errors.reply_to"><Input id="frt" v-model="form.reply_to" type="email" :placeholder="defaults.reply_to ?? ''" /></FormField>
                            </div>
                        </details>
                    </div>
                </DataCard>

                <DataCard class="p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-semibold">2 · Audiencia</h2>
                        <span class="flex items-center gap-2 rounded-full bg-accent px-3 py-1 text-sm font-semibold text-accent-foreground"><Users class="size-4" /><Spinner v-if="counting" class="size-3" /><template v-else>{{ (count ?? 0).toLocaleString('es-CL') }}</template> destinatarios únicos</span>
                    </div>
                    <p class="mb-4 text-xs text-muted-foreground">Se combinan las fuentes marcadas y se eliminan duplicados. Las direcciones dadas de baja, con rebote o spam se excluyen automáticamente.</p>

                    <div class="grid gap-4">
                        <div class="rounded-xl border p-4">
                            <p class="mb-2 text-sm font-medium">Listas</p>
                            <p v-if="!lists.length" class="text-xs text-muted-foreground">No hay listas. <Link href="/email/lists" class="text-primary hover:underline">Crear una</Link>.</p>
                            <div class="flex flex-wrap gap-2"><button v-for="l in lists" :key="l.id" type="button" :class="chip(audience.lists.includes(l.id))" @click="toggle(audience.lists, l.id)">{{ l.name }} <span class="text-muted-foreground">({{ l.entries_count }})</span></button></div>
                        </div>

                        <div class="rounded-xl border p-4">
                            <label class="flex items-center justify-between text-sm font-medium">Clientes del CRM<Switch :model-value="audience.leads.enabled" @update:model-value="(v: boolean) => (audience.leads.enabled = v)" /></label>
                            <div v-if="audience.leads.enabled" class="mt-4 grid gap-4">
                                <div><p class="mb-1.5 text-xs font-medium text-muted-foreground">Origen</p><div class="flex flex-wrap gap-2"><button v-for="s in sources" :key="s.id" type="button" :class="chip(audience.leads.sources.includes(s.id))" @click="toggle(audience.leads.sources, s.id)"><span class="size-2 rounded-full" :style="{ backgroundColor: s.color }" />{{ s.name }}</button></div></div>
                                <div><p class="mb-1.5 text-xs font-medium text-muted-foreground">Etapa</p><div class="flex flex-wrap gap-2"><button v-for="s in stages" :key="s.id" type="button" :class="chip(audience.leads.stages.includes(s.id))" @click="toggle(audience.leads.stages, s.id)"><span class="size-2 rounded-full" :style="{ backgroundColor: s.color }" />{{ s.name }}</button></div></div>
                                <div><p class="mb-1.5 text-xs font-medium text-muted-foreground">Prioridad</p><div class="flex flex-wrap gap-2"><button v-for="(l, k) in priorities" :key="k" type="button" :class="chip(audience.leads.priorities.includes(String(k)))" @click="toggle(audience.leads.priorities, String(k))"><span class="size-2 rounded-full" :style="{ backgroundColor: priorityMeta[k]?.color }" />{{ l }}</button></div></div>
                                <div><p class="mb-1.5 text-xs font-medium text-muted-foreground">Responsable</p><div class="flex flex-wrap gap-2"><button v-for="u in users" :key="u.id" type="button" :class="chip(audience.leads.assignees.includes(u.id))" @click="toggle(audience.leads.assignees, u.id)">{{ u.name }}</button></div></div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div><p class="mb-1.5 text-xs font-medium text-muted-foreground">Etiquetas (deben tener todas)</p><TagInput v-model="audience.leads.tags" /></div>
                                    <div class="grid grid-cols-2 gap-2"><div><p class="mb-1.5 text-xs font-medium text-muted-foreground">Ingresados desde</p><Input v-model="audience.leads.created_from" type="date" /></div><div><p class="mb-1.5 text-xs font-medium text-muted-foreground">hasta</p><Input v-model="audience.leads.created_to" type="date" /></div></div>
                                </div>
                                <p class="text-[11px] text-muted-foreground">Sin filtros = todos los clientes con correo.</p>
                            </div>
                        </div>

                        <div class="rounded-xl border p-4">
                            <label class="flex items-center justify-between text-sm font-medium">Empresas (talleres)<Switch :model-value="audience.clients.enabled" @update:model-value="(v: boolean) => (audience.clients.enabled = v)" /></label>
                            <label v-if="audience.clients.enabled" class="mt-3 flex items-center gap-2 text-sm text-muted-foreground"><Switch :model-value="audience.clients.only_active" @update:model-value="(v: boolean) => (audience.clients.only_active = v)" /> Solo clientes activos</label>
                        </div>
                    </div>
                </DataCard>

                <DataCard class="p-6">
                    <h2 class="mb-4 font-semibold">3 · Envío y seguimiento</h2>
                    <div class="grid gap-5">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :class="chip(mode === 'now')" @click="mode = 'now'"><Send class="size-3.5" /> Enviar ahora</button>
                            <button type="button" :class="chip(mode === 'schedule')" @click="mode = 'schedule'"><CalendarClock class="size-3.5" /> Programar</button>
                        </div>
                        <FormField v-if="mode === 'schedule'" label="Fecha y hora de envío" for="sa" hint="Hora de Chile. Requiere el scheduler de Laravel activo (cron schedule:run)."><Input id="sa" v-model="scheduledAt" type="datetime-local" class="w-64" /></FormField>
                        <div class="grid gap-3 text-sm">
                            <label class="flex items-center justify-between gap-3"><span>Medir aperturas <span class="text-xs text-muted-foreground">(pixel invisible)</span></span><Switch :model-value="form.track_opens" @update:model-value="(v: boolean) => (form.track_opens = v)" /></label>
                            <label class="flex items-center justify-between gap-3"><span>Medir clics <span class="text-xs text-muted-foreground">(enlaces con redirección)</span></span><Switch :model-value="form.track_clicks" @update:model-value="(v: boolean) => (form.track_clicks = v)" /></label>
                        </div>
                    </div>
                </DataCard>
            </div>

            <!-- Vista previa -->
            <div class="xl:sticky xl:top-4">
                <DataCard>
                    <div class="flex items-center justify-between border-b px-4 py-2.5">
                        <span class="flex items-center gap-2 text-sm font-semibold"><Eye class="size-4" /> Vista previa</span>
                        <Button variant="outline" size="sm" :disabled="!form.template_id" @click="testOpen = true; testMsg = null"><Send /> Enviarme prueba</Button>
                    </div>
                    <p class="truncate border-b bg-muted/40 px-4 py-2 text-xs"><span class="text-muted-foreground">Asunto:</span> {{ renderedSubject || '—' }}</p>
                    <div class="h-[560px]"><PreviewFrame v-if="html" :html="html" device="desktop" /><p v-else class="flex h-full items-center justify-center p-8 text-center text-sm text-muted-foreground">Elige una plantilla para ver cómo se verá el correo.</p></div>
                </DataCard>
            </div>
        </div>
    </div>

    <Dialog v-model:open="confirmOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ mode === 'now' ? '¿Enviar este boletín ahora?' : '¿Programar este boletín?' }}</DialogTitle>
                <DialogDescription>
                    Se enviará a <strong>{{ (count ?? 0).toLocaleString('es-CL') }}</strong> destinatarios con el asunto «{{ renderedSubject || form.subject }}»
                    <template v-if="mode === 'schedule'"> el <strong>{{ new Date(scheduledAt).toLocaleString('es-CL', { dateStyle: 'long', timeStyle: 'short' }) }}</strong></template>.
                    Esta acción no se puede deshacer una vez iniciado el envío.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2"><Button variant="outline" @click="confirmOpen = false">Revisar</Button><Button :disabled="sending" @click="doSend"><Spinner v-if="sending" /> {{ mode === 'now' ? 'Enviar' : 'Programar' }}</Button></DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="testOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Enviar prueba</DialogTitle><DialogDescription>Se envía una copia con datos de ejemplo.</DialogDescription></DialogHeader>
            <form class="grid gap-3" @submit.prevent="sendTest">
                <Input v-model="testTo" type="email" placeholder="tu@correo.cl" required />
                <p v-if="testMsg" :class="['rounded-xl p-3 text-sm', testMsg.ok ? 'bg-brand-green/10 text-brand-green' : 'bg-destructive/10 text-destructive']">{{ testMsg.text }}</p>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="testOpen = false">Cerrar</Button><Button type="submit" :disabled="testBusy"><Spinner v-if="testBusy" /> Enviar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
