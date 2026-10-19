<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Check, ClipboardCopy, Lightbulb, ListChecks, Mail, MessageCircle, Phone, RefreshCw, Sparkles, TriangleAlert, Wand2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { timeAgo } from '@/lib/format';
import { HttpError, sendJson } from '@/lib/http';
import { gradeMeta } from '@/lib/leadUi';
import { cn } from '@/lib/utils';
import { index as aiIndex } from '@/routes/ai';
import type { PanelData } from '@/types';

const props = defineProps<{ data: PanelData }>();
const emit = defineEmits<{ changed: [] }>();

const s = computed(() => props.data.scoring);
const ai = computed(() => props.data.ai);
const a = computed(() => ai.value.analysis);
const id = computed(() => props.data.lead.id);
const grade = computed(() => gradeMeta[s.value.grade]);

const R = 42;
const C = 2 * Math.PI * R;
const dash = computed(() => `${(s.value.score / 100) * C} ${C}`);

const busy = ref<'analyze' | 'rescore' | string | null>(null);
const copied = ref(false);

const call = async (key: string, url: string, body?: unknown, ok?: string) => {
    busy.value = key;
    try {
        await sendJson('POST', url, body);
        if (ok) toast.success(ok);
        emit('changed');
    } catch (e) {
        toast.error(e instanceof HttpError ? (e.body?.message ?? Object.values(e.fieldErrors)[0] ?? 'No se pudo completar la acción.') : 'No se pudo completar la acción.');
    } finally {
        busy.value = null;
    }
};

const rescore = () => call('rescore', `/leads/${id.value}/score`, undefined, 'Puntaje recalculado');
const analyze = () => call('analyze', `/leads/${id.value}/ai/analyze`, undefined, 'Análisis listo');
const apply = (field: string, value: string) => call(`apply-${field}`, `/leads/${id.value}/ai/apply`, { field, value }, 'Perfil actualizado');

const copy = async () => {
    if (!a.value) return;
    await navigator.clipboard.writeText(a.value.suggested_message);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1800);
};

const channel: Record<string, { label: string; icon: typeof Phone }> = {
    call: { label: 'Llamar', icon: Phone },
    email: { label: 'Correo', icon: Mail },
    whatsapp: { label: 'WhatsApp', icon: MessageCircle },
    meeting: { label: 'Reunión', icon: CalendarDays },
    task: { label: 'Tarea', icon: ListChecks },
};
const when: Record<string, string> = { today: 'Hoy', this_week: 'Esta semana', later: 'Más adelante' };
const temp: Record<string, { label: string; color: string }> = {
    hot: { label: 'Caliente', color: '#027BB9' },
    warm: { label: 'Tibio', color: '#FFA165' },
    cold: { label: 'Frío', color: '#1AA0E4' },
};
const fieldLabel: Record<string, string> = { priority: 'Prioridad', tags: 'Etiquetas', company: 'Empresa', job_title: 'Cargo' };
const priorityLabel = (v: string) => props.data.priorities[v] ?? v;
const barColor = (g: { points: number; max: number }) => (g.points / g.max >= 0.7 ? '#0D9F85' : g.points / g.max >= 0.4 ? '#FFA165' : '#C9C9C9');
const canUpdate = computed(() => props.data.can.update);
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Puntaje -->
        <section class="grid gap-4 rounded-2xl border bg-card p-4 sm:grid-cols-[auto_1fr] sm:items-center">
            <div class="relative mx-auto size-28 shrink-0">
                <svg viewBox="0 0 100 100" class="size-full -rotate-90">
                    <circle cx="50" cy="50" :r="R" fill="none" stroke="currentColor" class="text-muted" stroke-width="9" />
                    <circle cx="50" cy="50" :r="R" fill="none" :stroke="grade.color" stroke-width="9" stroke-linecap="round" :stroke-dasharray="dash" />
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-3xl leading-none font-bold">{{ s.score }}</span>
                    <span class="mt-1 text-[11px] text-muted-foreground">de 100</span>
                </div>
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: grade.color }">{{ grade.label }}</span>
                    <span v-if="s.breakdown.ai_adjustment" class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary" title="Ajuste propuesto por la IA">IA {{ s.breakdown.ai_adjustment > 0 ? '+' : '' }}{{ s.breakdown.ai_adjustment }}</span>
                </div>
                <p v-if="s.breakdown.note" class="mt-1.5 text-xs text-muted-foreground">{{ s.breakdown.note }}</p>
                <div class="mt-3">
                    <div class="flex items-center justify-between text-xs"><span class="font-medium">Perfil completo</span><span class="font-semibold">{{ s.completeness }}%</span></div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${s.completeness}%` }" /></div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground">
                    <span v-if="s.scored_at">Calculado {{ timeAgo(s.scored_at) }} · se actualiza solo con cada dato nuevo</span>
                    <Button variant="ghost" size="sm" class="h-7 px-2.5 text-xs" :disabled="busy === 'rescore'" @click="rescore"><Spinner v-if="busy === 'rescore'" /><RefreshCw v-else /> Recalcular</Button>
                </div>
            </div>
        </section>

        <!-- Desglose -->
        <section class="rounded-2xl border bg-card p-4">
            <h3 class="mb-3 text-sm font-semibold">¿Por qué este puntaje?</h3>
            <div class="grid gap-3">
                <details v-for="g in s.breakdown.groups" :key="g.key" class="group">
                    <summary class="cursor-pointer list-none">
                        <div class="flex items-center justify-between text-xs"><span class="font-medium">{{ g.label }}</span><span class="text-muted-foreground">{{ g.points }}/{{ g.max }}</span></div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full" :style="{ width: `${(g.points / g.max) * 100}%`, backgroundColor: barColor(g) }" /></div>
                    </summary>
                    <ul class="mt-2 grid gap-1 pl-1 text-xs">
                        <li v-for="i in g.items" :key="i.label" class="flex items-start gap-2">
                            <Check v-if="i.ok" class="mt-0.5 size-3.5 shrink-0 text-brand-green" />
                            <span v-else class="mt-1.5 ml-1 size-1.5 shrink-0 rounded-full bg-muted-foreground/40" />
                            <span :class="i.ok ? '' : 'text-muted-foreground'">{{ i.label }}<em v-if="i.hint" class="ml-1 text-[11px] not-italic opacity-80">— {{ i.hint }}</em></span>
                            <span class="ml-auto shrink-0 text-muted-foreground">{{ i.points }}/{{ i.max }}</span>
                        </li>
                    </ul>
                </details>
            </div>
        </section>

        <!-- Para mejorar el perfil -->
        <section v-if="s.missing.length" class="rounded-2xl border bg-card p-4">
            <h3 class="mb-1 flex items-center gap-2 text-sm font-semibold"><Lightbulb class="size-4 text-primary" /> Datos que mejorarían este perfil</h3>
            <p class="mb-3 text-xs text-muted-foreground">Cada dato que completes sube la precisión del puntaje. Pídelo en tu próximo contacto.</p>
            <ul class="grid gap-2">
                <li v-for="m in s.missing.slice(0, 6)" :key="m.field" class="flex items-start justify-between gap-3 rounded-xl bg-muted/40 px-3 py-2 text-xs">
                    <span><strong>{{ m.label }}</strong><span class="block text-muted-foreground">{{ m.why }}</span></span>
                    <span class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 font-semibold text-primary">+{{ m.impact }}%</span>
                </li>
            </ul>
        </section>

        <!-- Señales detectadas -->
        <section v-if="s.signals.length" class="rounded-2xl border bg-card p-4">
            <h3 class="mb-3 text-sm font-semibold">Señales detectadas automáticamente</h3>
            <dl class="grid gap-1.5 text-xs sm:grid-cols-2">
                <div v-for="g in s.signals" :key="g.key" class="rounded-xl bg-muted/40 px-3 py-2"><dt class="text-muted-foreground">{{ g.label }}</dt><dd class="font-medium break-words">{{ g.value }}</dd></div>
            </dl>
        </section>

        <!-- IA -->
        <section v-if="ai.can_use" class="rounded-2xl border border-primary/30 bg-primary/[0.03] p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="flex items-center gap-2 text-sm font-semibold"><Sparkles class="size-4 text-primary" /> Análisis con IA <span class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">Opcional</span></h3>
                <Button v-if="ai.available" size="sm" :variant="a ? 'outline' : 'default'" :disabled="busy === 'analyze'" @click="analyze">
                    <Spinner v-if="busy === 'analyze'" /><Wand2 v-else /> {{ busy === 'analyze' ? 'Analizando…' : a ? 'Actualizar análisis' : 'Analizar este cliente' }}
                </Button>
            </div>

            <p v-if="!ai.available" class="mt-3 rounded-xl border border-dashed p-3 text-xs text-muted-foreground">
                No hay un proveedor de IA activo.
                <Link v-if="ai.can_configure" :href="aiIndex()" class="font-medium text-primary hover:underline">Configúralo aquí</Link>
                <span v-else>Pide a un administrador que lo configure.</span>
            </p>

            <p v-else-if="!a" class="mt-3 text-xs text-muted-foreground">La IA resume al cliente, propone los próximos pasos, redacta un primer mensaje y sugiere qué datos completar. Usa el puntaje y el perfil de arriba como base.</p>

            <template v-if="a">
                <p v-if="ai.stale" class="mt-3 flex items-center gap-2 rounded-xl bg-[#FFA165]/15 px-3 py-2 text-xs text-[#9A4B00]"><TriangleAlert class="size-4 shrink-0" /> El cliente cambió desde este análisis. Actualízalo para obtener recomendaciones al día.</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" :style="{ backgroundColor: temp[a.temperature].color }">{{ temp[a.temperature].label }}</span>
                    <span class="text-[11px] text-muted-foreground">{{ timeAgo(a.generated_at) }} · {{ a.provider }}{{ a.model ? ` · ${a.model}` : '' }}</span>
                </div>
                <p class="mt-2 text-sm">{{ a.summary }}</p>
                <p v-if="a.adjustment_reason" class="mt-1 text-xs text-muted-foreground"><strong>Ajuste {{ a.score_adjustment > 0 ? '+' : '' }}{{ a.score_adjustment }}:</strong> {{ a.adjustment_reason }}</p>

                <div v-if="a.buying_signals.length || a.risks.length" class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div v-if="a.buying_signals.length"><p class="mb-1 text-xs font-semibold text-brand-green">Señales de compra</p><ul class="grid gap-1 text-xs"><li v-for="x in a.buying_signals" :key="x" class="flex gap-1.5"><Check class="mt-0.5 size-3.5 shrink-0 text-brand-green" />{{ x }}</li></ul></div>
                    <div v-if="a.risks.length"><p class="mb-1 text-xs font-semibold text-[#C23F00]">Riesgos</p><ul class="grid gap-1 text-xs"><li v-for="x in a.risks" :key="x" class="flex gap-1.5"><TriangleAlert class="mt-0.5 size-3.5 shrink-0 text-[#C23F00]" />{{ x }}</li></ul></div>
                </div>

                <div v-if="a.next_actions.length" class="mt-4">
                    <p class="mb-2 text-xs font-semibold">Próximos pasos sugeridos</p>
                    <ol class="grid gap-2">
                        <li v-for="(n, i) in a.next_actions" :key="i" class="flex items-start gap-3 rounded-xl border bg-card px-3 py-2">
                            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-primary/10 text-primary [&_svg]:size-3.5"><component :is="(channel[n.channel] ?? channel.task).icon" /></span>
                            <span class="min-w-0 flex-1 text-xs"><strong class="block text-sm">{{ n.action }}</strong><span class="text-muted-foreground">{{ n.why }}</span></span>
                            <span :class="cn('shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold', n.when === 'today' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground')">{{ when[n.when] }}</span>
                        </li>
                    </ol>
                </div>

                <div v-if="a.suggested_message" class="mt-4">
                    <div class="mb-1 flex items-center justify-between"><p class="text-xs font-semibold">Primer mensaje sugerido</p><Button variant="ghost" size="sm" class="h-7 px-2.5 text-xs" @click="copy"><Check v-if="copied" /><ClipboardCopy v-else /> {{ copied ? 'Copiado' : 'Copiar' }}</Button></div>
                    <p class="rounded-xl bg-card p-3 text-xs leading-relaxed whitespace-pre-line">{{ a.suggested_message }}</p>
                </div>

                <div v-if="a.questions_to_ask.length" class="mt-4">
                    <p class="mb-1 text-xs font-semibold">Preguntas para calificarlo (completan su perfil)</p>
                    <ul class="grid gap-1 text-xs"><li v-for="x in a.questions_to_ask" :key="x" class="flex gap-1.5"><span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary" />{{ x }}</li></ul>
                </div>

                <div v-if="a.profile_suggestions.length && canUpdate" class="mt-4">
                    <p class="mb-2 text-xs font-semibold">Mejoras al perfil (tú decides)</p>
                    <ul class="grid gap-2">
                        <li v-for="p in a.profile_suggestions" :key="p.field + p.value" class="flex items-center justify-between gap-3 rounded-xl border bg-card px-3 py-2 text-xs">
                            <span class="min-w-0"><strong>{{ fieldLabel[p.field] }}:</strong> {{ p.field === 'priority' ? priorityLabel(p.value) : p.value }}<span class="block text-muted-foreground">{{ p.reason }} · confianza {{ p.confidence === 'high' ? 'alta' : p.confidence === 'medium' ? 'media' : 'baja' }}</span></span>
                            <Button size="sm" class="h-7 px-2.5 text-xs" variant="outline" :disabled="busy === `apply-${p.field}`" @click="apply(p.field, p.value)"><Spinner v-if="busy === `apply-${p.field}`" />Aplicar</Button>
                        </li>
                    </ul>
                </div>
                <p class="mt-3 text-[11px] text-muted-foreground">{{ a.shared_contact ? 'Se enviaron datos de contacto al proveedor de IA (configurable en Inteligencia artificial).' : 'No se enviaron nombre, correo, teléfono ni notas al proveedor de IA.' }}</p>
            </template>
        </section>
    </div>
</template>
