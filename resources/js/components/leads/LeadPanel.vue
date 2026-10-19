<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    FileSignature,
    Gauge,
    Building2,
    CalendarClock,
    CalendarDays,
    CircleDot,
    Globe,
    ListChecks,
    Lock,
    Mail,
    MapPin,
    MessageCircle,
    Monitor,
    Pencil,
    Phone,
    Sparkles,
    StickyNote,
    Trash2,
    UserCheck,
    Link2,
    Unlink,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CloseStageDialog from '@/components/kanban/CloseStageDialog.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import AttachProposalDialog from '@/components/leads/AttachProposalDialog.vue';
import LeadProfile from '@/components/leads/LeadProfile.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import SourceIcon from '@/components/SourceIcon.vue';
import TagInput from '@/components/TagInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import UserInitials from '@/components/UserInitials.vue';
import { formatDateTime, timeAgo } from '@/lib/format';
import { sendJson } from '@/lib/http';
import { followUpClass, followUpInfo, formatAmount, formatMoney, gradeMeta, priorityMeta, toLocalInput, whatsappUrl } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { assign, edit, move, quick } from '@/routes/leads';
import { store as storeActivity } from '@/routes/leads/activities';
import { destroy as destroyNote, store as storeNote } from '@/routes/leads/notes';
import type { LeadNote, PanelData } from '@/types';

const props = withDefaults(defineProps<{ data: PanelData; layout?: 'page' | 'drawer'; tagSuggestions?: string[] }>(), {
    layout: 'page',
});
const emit = defineEmits<{ changed: [] }>();

const lead = computed(() => props.data.lead);
const can = computed(() => props.data.can);

// ---- campos rápidos (autoguardado) ----
const q = reactive({
    stageId: lead.value.stage.id as number,
    assignee: (lead.value.assignee?.id ?? '') as number | string,
    priority: lead.value.priority as string,
    value: lead.value.estimated_amount ? String(lead.value.estimated_amount) : '',
    currency: (lead.value.estimated_currency ?? 'CLP') as 'CLP' | 'UF',
    followUp: toLocalInput(lead.value.next_follow_up_at),
    tags: [...lead.value.tags],
});
watch(
    () => props.data,
    (d) => {
        q.stageId = d.lead.stage.id;
        q.assignee = d.lead.assignee?.id ?? '';
        q.priority = d.lead.priority;
        q.value = d.lead.estimated_amount ? String(d.lead.estimated_amount) : '';
        q.currency = d.lead.estimated_currency ?? 'CLP';
        q.followUp = toLocalInput(d.lead.next_follow_up_at);
        q.tags = [...d.lead.tags];
    },
);

const run = async (fn: () => Promise<unknown>, ok?: string) => {
    try {
        await fn();
        if (ok) toast.success(ok);
        emit('changed');
    } catch {
        toast.error('No se pudo guardar el cambio.');
        emit('changed');
    }
};

const attachOpen = ref(false);
const detachProposal = (id: number) => run(() => sendJson('DELETE', `/leads/${lead.value.id}/proposals/${id}/attach`), 'Propuesta desvinculada');

const saveQuick = (patch: Record<string, unknown>) => run(() => sendJson('PATCH', quick(lead.value.id).url, patch));

// etapa (con diálogo de cierre para concretado / descartado)
const closing = ref<{ stageId: number; type: 'won' | 'lost' | null; name: string } | null>(null);
const changeStage = () => {
    const target = props.data.stages.find((s) => s.id === Number(q.stageId));
    if (!target || target.id === lead.value.stage.id) return;
    if (target.type !== 'open') {
        closing.value = { stageId: target.id, type: target.type, name: target.name };
        return;
    }
    run(async () => offerProposal(await sendJson<{ needs_proposal?: boolean }>('PUT', move(lead.value.id).url, { stage_id: target.id })), `Movido a ${target.name}`);
};
const offerProposal = (res: { needs_proposal?: boolean }) => {
    if (res?.needs_proposal && props.data.can.create_proposal) {
        toast('Esta etapa pide una propuesta comercial', { action: { label: 'Crear propuesta', onClick: () => router.visit(`/proposals/create?lead=${lead.value.id}`) }, duration: 9000 });
    }
};
const confirmClose = (extra: { lost_reason: string | null; estimated_amount: number | null; estimated_currency: 'CLP' | 'UF' }) => {
    const c = closing.value;
    closing.value = null;
    if (c) run(() => sendJson('PUT', move(lead.value.id).url, { stage_id: c.stageId, ...extra }), `Movido a ${c.name}`);
};
const cancelClose = () => {
    closing.value = null;
    q.stageId = lead.value.stage.id;
};

const changeAssignee = () => run(() => sendJson('PUT', assign(lead.value.id).url, { assigned_to: q.assignee || null }), 'Responsable actualizado');
const saveFollowUp = (iso: string | null) => {
    q.followUp = iso ? toLocalInput(iso) : '';
    saveQuick({ next_follow_up_at: iso });
};
const inDays = (d: number) => {
    const date = new Date();
    date.setDate(date.getDate() + d);
    date.setHours(10, 0, 0, 0);
    return date.toISOString();
};
const followUp = computed(() => followUpInfo(lead.value.next_follow_up_at));

// ---- seguimiento y notas ----
const fu = reactive({ type: 'call', description: '', occurred_at: '', busy: false });
const addFollowUp = async () => {
    fu.busy = true;
    await run(
        () => sendJson('POST', storeActivity(lead.value.id).url, { type: fu.type, description: fu.description, occurred_at: fu.occurred_at || null }),
        'Seguimiento registrado',
    );
    fu.description = '';
    fu.occurred_at = '';
    fu.busy = false;
};
const note = reactive({ body: '', is_private: false, busy: false });
const addNote = async () => {
    note.busy = true;
    await run(() => sendJson('POST', storeNote(lead.value.id).url, { body: note.body, is_private: note.is_private }), note.is_private ? 'Nota privada guardada' : 'Nota guardada');
    note.body = '';
    note.is_private = false;
    note.busy = false;
};
const removeNote = (n: LeadNote) => run(() => sendJson('DELETE', destroyNote({ lead: lead.value.id, note: n.id }).url), 'Nota eliminada');

const typeMeta: Record<string, { icon: typeof Phone; color: string }> = {
    created: { icon: Sparkles, color: '#027BB9' },
    stage_changed: { icon: ArrowRightLeft, color: '#6419DB' },
    assigned: { icon: UserCheck, color: '#1AA0E4' },
    updated: { icon: Pencil, color: '#8A8A8A' },
    call: { icon: Phone, color: '#0D9F85' },
    email: { icon: Mail, color: '#4A8CFF' },
    whatsapp: { icon: MessageCircle, color: '#25D366' },
    meeting: { icon: CalendarDays, color: '#DF1E79' },
    task: { icon: ListChecks, color: '#FFA165' },
    proposal: { icon: FileSignature, color: '#027BB9' },
    other: { icon: CircleDot, color: '#8A8A8A' },
};
const meta = (t: string) => typeMeta[t] ?? typeMeta.other;

const utmEntries = computed(() => Object.entries(lead.value.utm).filter(([, v]) => v));
const location = computed(() => [lead.value.capture.city, lead.value.capture.region, lead.value.capture.country].filter(Boolean).join(', '));
const mapUrl = computed(() =>
    lead.value.capture.latitude != null && lead.value.capture.longitude != null
        ? `https://www.openstreetmap.org/?mlat=${lead.value.capture.latitude}&mlon=${lead.value.capture.longitude}#map=12/${lead.value.capture.latitude}/${lead.value.capture.longitude}`
        : null,
);
const device = computed(() => {
    const ua = String(lead.value.capture.user_agent ?? '');
    if (!ua) return null;
    const kind = /mobile|iphone|android/i.test(ua) ? 'Móvil' : /ipad|tablet/i.test(ua) ? 'Tablet' : 'Escritorio';
    const browser = /edg\//i.test(ua) ? 'Edge' : /chrome|crios/i.test(ua) ? 'Chrome' : /firefox|fxios/i.test(ua) ? 'Firefox' : /safari/i.test(ua) ? 'Safari' : '';
    return [kind, browser].filter(Boolean).join(' · ');
});
const metaEntries = computed(() => Object.entries(lead.value.meta ?? {}));
const showValue = (type: string, v: unknown) => (type === 'checkbox' ? (v ? 'Sí' : 'No') : String(v));
const wa = computed(() => whatsappUrl(lead.value.phone));
const page = computed(() => props.layout === 'page');
const tab = ref('followup');
const sc = computed(() => props.data.scoring);
</script>

<template>
    <div :class="cn('flex flex-col gap-5', page && 'lg:grid lg:grid-cols-3 lg:items-start')">
        <div :class="cn('flex min-w-0 flex-col gap-5', page && 'lg:col-span-2')">
            <!-- Puntaje del lead -->
            <button type="button" class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-3 text-left transition hover:border-primary/40" title="Ver por qué tiene este puntaje" @click="tab = 'profile'">
                <span class="grid size-11 shrink-0 place-items-center rounded-full text-lg font-bold text-white" :style="{ backgroundColor: gradeMeta[sc.grade].color }">{{ sc.score }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-semibold">{{ gradeMeta[sc.grade].label }}</span>
                    <span class="block text-xs text-muted-foreground">Perfil {{ sc.completeness }}% completo<template v-if="sc.missing.length"> · falta {{ sc.missing[0].label.toLowerCase() }}</template></span>
                </span>
                <span class="text-xs font-medium text-primary">Ver perfil</span>
            </button>

            <!-- Gestión rápida -->
            <div class="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-2">
                <div>
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">Etapa</p>
                    <div class="flex items-center gap-2">
                        <span class="size-3 shrink-0 rounded-full" :style="{ backgroundColor: data.stages.find((s) => s.id === Number(q.stageId))?.color }" />
                        <NativeSelect v-model="q.stageId" :disabled="!can.move" class="flex-1" @update:model-value="changeStage">
                            <option v-for="s in data.stages" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </NativeSelect>
                    </div>
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">Responsable</p>
                    <div class="flex items-center gap-2">
                        <UserInitials :name="lead.assignee?.name" class="size-7" />
                        <NativeSelect v-if="can.assign" v-model="q.assignee" class="flex-1" @update:model-value="changeAssignee">
                            <option value="">Sin asignar</option>
                            <option v-for="u in data.users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </NativeSelect>
                        <span v-else class="text-sm">{{ lead.assignee?.name ?? 'Sin asignar' }}</span>
                    </div>
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">Prioridad</p>
                    <div class="flex items-center gap-2">
                        <span class="size-3 shrink-0 rounded-full" :style="{ backgroundColor: priorityMeta[q.priority]?.color }" />
                        <NativeSelect v-model="q.priority" :disabled="!can.update" class="flex-1" @update:model-value="saveQuick({ priority: q.priority })">
                            <option v-for="(label, key) in data.priorities" :key="key" :value="key">{{ label }}</option>
                        </NativeSelect>
                    </div>
                </div>
                <div>
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">Valor estimado</p>
                    <MoneyInput v-model="q.value" v-model:currency="q.currency" :disabled="!can.update" @commit="saveQuick({ estimated_amount: q.value === '' ? null : Number(q.value), estimated_currency: q.currency })" />
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-1.5 flex items-center gap-2 text-xs font-medium text-muted-foreground">
                        <CalendarClock class="size-3.5" /> Próximo seguimiento
                        <span v-if="followUp" :class="cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', followUpClass[followUp.state])">{{ followUp.label }}</span>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Input v-model="q.followUp" type="datetime-local" :disabled="!can.update" class="w-auto" @change="saveFollowUp(q.followUp ? new Date(q.followUp).toISOString() : null)" />
                        <template v-if="can.update">
                            <Button type="button" variant="outline" size="sm" @click="saveFollowUp(inDays(1))">Mañana</Button>
                            <Button type="button" variant="outline" size="sm" @click="saveFollowUp(inDays(3))">En 3 días</Button>
                            <Button type="button" variant="outline" size="sm" @click="saveFollowUp(inDays(7))">En 1 semana</Button>
                            <Button v-if="q.followUp" type="button" variant="ghost" size="sm" @click="saveFollowUp(null)">Quitar</Button>
                        </template>
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-1.5 text-xs font-medium text-muted-foreground">Etiquetas</p>
                    <TagInput v-if="can.update" v-model="q.tags" :suggestions="tagSuggestions" @update:model-value="saveQuick({ tags: q.tags })" />
                    <div v-else class="flex flex-wrap gap-1.5">
                        <span v-for="t in lead.tags" :key="t" class="rounded-full bg-accent px-2 py-0.5 text-xs text-accent-foreground">{{ t }}</span>
                        <span v-if="!lead.tags.length" class="text-sm text-muted-foreground">Sin etiquetas</span>
                    </div>
                </div>
            </div>

            <p v-if="lead.lost_reason" class="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-2.5 text-sm">
                <strong>Motivo de descarte:</strong> {{ lead.lost_reason }}
            </p>

            <!-- Seguimiento / Notas / Datos -->
            <Tabs v-model="tab">
                <TabsList class="h-auto w-full flex-wrap justify-start gap-0.5 rounded-2xl">
                    <TabsTrigger value="followup"><ListChecks /> Seguimiento</TabsTrigger>
                    <TabsTrigger v-if="can.proposals" value="proposals"><FileSignature /> Propuestas ({{ data.proposals.length }})</TabsTrigger>
                    <TabsTrigger value="profile"><Gauge /> Perfil e IA</TabsTrigger>
                    <TabsTrigger value="notes"><StickyNote /> Notas ({{ data.notes.length }})</TabsTrigger>
                    <TabsTrigger v-if="!page" value="data"><Globe /> Datos</TabsTrigger>
                </TabsList>

                <TabsContent value="followup" class="mt-3 flex flex-col gap-4">
                    <form v-if="can.note" class="grid gap-3 rounded-2xl border bg-card p-4" @submit.prevent="addFollowUp">
                        <div :class="cn('grid gap-3', page ? 'sm:grid-cols-[10rem_1fr_14rem]' : 'grid-cols-1 sm:grid-cols-2')">
                            <NativeSelect v-model="fu.type">
                                <option v-for="(label, key) in data.followUpTypes" :key="key" :value="key">{{ label }}</option>
                            </NativeSelect>
                            <Input v-model="fu.occurred_at" type="datetime-local" title="Fecha (por defecto, ahora)" :class="page ? 'order-3' : ''" />
                            <Input v-model="fu.description" placeholder="¿Qué pasó? Ej: Llamé, pidió cotización" :class="page ? '' : 'sm:col-span-2'" />
                        </div>
                        <div class="flex justify-end">
                            <Button type="submit" size="sm" :disabled="fu.busy || !fu.description.trim()"><Spinner v-if="fu.busy" /> Registrar seguimiento</Button>
                        </div>
                    </form>

                    <ol class="relative flex flex-col gap-4 border-l pl-6">
                        <li v-for="a in data.activities" :key="a.id" class="relative">
                            <span class="absolute -left-[2.15rem] flex size-7 items-center justify-center rounded-full text-white ring-4 ring-background [&_svg]:size-3.5" :style="{ backgroundColor: meta(a.type).color }">
                                <component :is="meta(a.type).icon" />
                            </span>
                            <p class="text-sm">
                                <span v-if="data.followUpTypes[a.type]" class="font-semibold">{{ data.followUpTypes[a.type] }}: </span>{{ a.description }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ a.user ?? 'Sistema' }} · {{ formatDateTime(a.occurred_at) }} ({{ timeAgo(a.occurred_at) }})</p>
                        </li>
                    </ol>
                </TabsContent>

                <TabsContent v-if="can.proposals" value="proposals" class="mt-3 flex flex-col gap-3">
                    <div class="flex flex-col gap-3 rounded-2xl border bg-card p-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-muted-foreground">{{ data.proposals.length ? 'Puedes crear otra propuesta o una nueva versión para este cliente.' : 'Este cliente aún no tiene propuestas comerciales.' }}</p>
                        <div v-if="can.create_proposal" class="flex shrink-0 flex-wrap justify-end gap-2">
                            <Button size="sm" variant="outline" @click="attachOpen = true"><Link2 /> Asociar existente</Button>
                            <Button size="sm" as-child><Link :href="`/proposals/create?lead=${lead.id}`"><FileSignature /> Nueva propuesta</Link></Button>
                        </div>
                    </div>
                    <Link v-for="pr in data.proposals" :key="pr.id" :href="`/proposals/${pr.id}`" class="flex items-center justify-between gap-3 rounded-2xl border bg-card p-4 transition hover:border-primary/40">
                        <span class="min-w-0"><span class="block truncate text-sm font-semibold">{{ pr.title }}</span><span class="text-xs text-muted-foreground">{{ pr.number }} · {{ timeAgo(pr.created_at) }}<template v-if="pr.valid_until"> · vence {{ pr.valid_until }}</template></span></span>
                        <span class="flex shrink-0 items-center gap-2">
                            <span class="flex flex-col items-end gap-1"><strong class="text-sm">{{ formatAmount(pr.total_net, pr.currency) }}</strong><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: pr.status_color }">{{ pr.status_label }}</span></span>
                            <button v-if="can.create_proposal" type="button" class="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-destructive" title="Desvincular de este cliente (no la elimina)" @click.prevent.stop="detachProposal(pr.id)"><Unlink class="size-4" /></button>
                        </span>
                    </Link>
                    <AttachProposalDialog v-model:open="attachOpen" :lead-id="lead.id" @attached="emit('changed')" />
                </TabsContent>

                <TabsContent value="profile" class="mt-3">
                    <LeadProfile :data="data" @changed="emit('changed')" />
                </TabsContent>

                <TabsContent value="notes" class="mt-3 flex flex-col gap-4">
                    <form v-if="can.note" class="grid gap-3 rounded-2xl border bg-card p-4" @submit.prevent="addNote">
                        <RichTextEditor v-model="note.body" compact :min-height="80" placeholder="Escribe una nota interna sobre este cliente…" />
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="flex items-center gap-2.5 text-sm">
                                <Switch :model-value="note.is_private" @update:model-value="(v: boolean) => (note.is_private = v)" />
                                <Lock class="size-3.5 text-muted-foreground" /> Privada (solo yo)
                            </label>
                            <Button type="submit" size="sm" :disabled="note.busy || !note.body.trim()"><Spinner v-if="note.busy" /> Guardar nota</Button>
                        </div>
                    </form>
                    <p v-if="!data.notes.length" class="rounded-2xl border border-dashed bg-card py-8 text-center text-sm text-muted-foreground">Aún no hay notas.</p>
                    <article v-for="n in data.notes" :key="n.id" class="rounded-2xl border p-4" :class="n.is_private ? 'border-primary/30 bg-accent/50' : 'bg-card'">
                        <div class="rich text-sm" v-html="n.body_html" />
                        <div class="mt-2 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span class="flex items-center gap-1.5">
                                <Lock v-if="n.is_private" class="size-3 text-primary" />{{ n.author }} · {{ timeAgo(n.created_at) }}
                                <em v-if="n.is_private" class="text-primary not-italic">· privada</em>
                            </span>
                            <Button v-if="n.mine || can.delete" variant="ghost" size="icon-sm" class="size-6 text-destructive hover:text-destructive" title="Eliminar nota" @click="removeNote(n)"><Trash2 /></Button>
                        </div>
                    </article>
                </TabsContent>

                <TabsContent v-if="!page" value="data" class="mt-3">
                    <slot name="details" />
                </TabsContent>
            </Tabs>
        </div>

        <!-- Datos (columna lateral en página completa) -->
        <div v-if="page" class="flex flex-col gap-4">
            <slot name="details" />
        </div>

        <CloseStageDialog
            :open="!!closing"
            :type="closing?.type ?? null"
            :stage-name="closing?.name ?? ''"
            :lead-name="lead.full_name"
            @update:open="(v: boolean) => !v && (closing = null)"
            @confirm="confirmClose"
            @cancel="cancelClose"
        />
    </div>
</template>
