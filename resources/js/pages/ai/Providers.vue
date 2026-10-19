<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, CircleAlert, ExternalLink, KeyRound, Lock, Mic, Plug, RefreshCw, RotateCcw, ShieldCheck, SlidersHorizontal, Sparkles, Star, Trash2, Zap } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { timeAgo } from '@/lib/format';
import { HttpError, sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { index } from '@/routes/ai';
import { defaultMethod as makeDefault, destroy, models as modelsRoute, test as testRoute, testVoice as testVoiceRoute, update } from '@/routes/ai/providers';
import { update as updateSettings } from '@/routes/ai/settings';
import { update as updateTuning } from '@/routes/ai/tuning';

type Provider = {
    slug: string; name: string; color: string; description: string; keys_url: string | null; key_hint_format: string; url_editable: boolean; url_required: boolean;
    stt: { supported: boolean; suggest: string[]; default: string | null; model: string | null; effective: string | null; in_use: boolean };
    default_url: string; suggest: string[]; configured: boolean; has_key: boolean; key_masked: string | null; base_url: string | null; model: string | null;
    is_enabled: boolean; is_default: boolean; usable: boolean; last_tested_at: string | null; last_test_ok: boolean | null; last_test_message: string | null; last_test_ms: number | null;
};

const props = defineProps<{
    providers: Provider[]; available: boolean;
    settings: { auto_analyze: boolean; share_contact: boolean; brand_context: string; brand_context_is_default: boolean };
    tuning: { instructions: string; agent_instructions: string; temperature: string; reasoning_effort: string; max_tokens: number | null; agent_max_steps: number | null; transcription_prompt: string };
    tuningOptions: { temperatures: Record<string, string>; reasoning: Record<string, string> };
    defaultModel: string | null;
    reasoningModel: boolean;
    usage: { feature: string; runs: number; input_tokens: number; output_tokens: number; errors: number }[];
    recentErrors: { feature: string; provider: string | null; error: string | null; created_at: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Proveedores de IA', href: index() }] } });

const featureLabel: Record<string, string> = { email_design: 'Diseño de mailings', email_design_fallback: 'Diseño de mailings', subjects: 'Asuntos de correo', subjects_fallback: 'Asuntos de correo', lead_analysis: 'Análisis de clientes', lead_analysis_fallback: 'Análisis de clientes', test: 'Pruebas de conexión' };

// ---------------------------------------------------------------- configurar
const editing = ref<Provider | null>(null);
const form = useForm({ api_key: '', base_url: '', model: '', transcription_model: '', is_enabled: true });
const models = ref<{ id: string; name: string }[]>([]);
const loadingModels = ref(false);
const modelsError = ref('');

const open = (p: Provider) => {
    editing.value = p;
    form.defaults({ api_key: '', base_url: p.base_url ?? p.default_url ?? '', model: p.model ?? '', transcription_model: p.stt.model ?? '', is_enabled: p.is_enabled }).reset();
    form.clearErrors();
    models.value = [];
    modelsError.value = '';
};
const save = (andTest = false) => {
    if (!editing.value) return;
    const slug = editing.value.slug;
    form.submit(update(slug), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
            if (andTest) setTimeout(() => runTest(slug), 400);
        },
    });
};

const loadModels = async () => {
    if (!editing.value) return;
    loadingModels.value = true;
    modelsError.value = '';
    try {
        // Para listar necesitamos la key ya guardada; si se escribió una nueva, se guarda primero.
        if (form.api_key || !editing.value.has_key) {
            modelsError.value = 'Guarda la API key primero (botón «Guardar») y luego carga los modelos.';
            return;
        }
        const r = await sendJson<{ models: { id: string; name: string }[]; error: string | null }>('GET', modelsRoute(editing.value.slug).url);
        models.value = r.models;
        modelsError.value = r.error ?? '';
    } catch {
        modelsError.value = 'No se pudieron cargar los modelos.';
    } finally {
        loadingModels.value = false;
    }
};

// ---------------------------------------------------------------- probar
const testing = reactive<Record<string, boolean>>({});
const results = reactive<Record<string, { ok: boolean; message: string; ms: number }>>({});
const runTest = async (slug: string) => {
    testing[slug] = true;
    try {
        results[slug] = await sendJson('POST', testRoute(slug).url);
    } catch (e) {
        const b = e instanceof HttpError ? (e.body as { message?: string } | null) : null;
        results[slug] = { ok: false, message: b?.message ?? 'No se pudo probar.', ms: 0 };
    } finally {
        testing[slug] = false;
        router.reload({ only: ['providers', 'available', 'recentErrors'] });
    }
};

const testingVoice = reactive<Record<string, boolean>>({});
const voiceResults = reactive<Record<string, { ok: boolean; message: string; ms: number }>>({});
const runVoiceTest = async (slug: string) => {
    testingVoice[slug] = true;
    try {
        voiceResults[slug] = await sendJson('POST', testVoiceRoute(slug).url);
    } catch (e) {
        const b = e instanceof HttpError ? (e.body as { message?: string } | null) : null;
        voiceResults[slug] = { ok: false, message: b?.message ?? 'No se pudo probar la voz.', ms: 0 };
    } finally {
        testingVoice[slug] = false;
    }
};

const setDefault = (p: Provider) => router.post(makeDefault(p.slug).url, {}, { preserveScroll: true });
const toDelete = ref<Provider | null>(null);
const confirmDelete = () => toDelete.value && router.delete(destroy(toDelete.value.slug).url, { preserveScroll: true, onFinish: () => (toDelete.value = null) });

// ---------------------------------------------------------------- preferencias
const tuning = useForm({ ...props.tuning, max_tokens: (props.tuning.max_tokens ?? '') as number | '', agent_max_steps: (props.tuning.agent_max_steps ?? '') as number | '' });
const saveTuning = () => tuning.transform((d) => ({ ...d, max_tokens: d.max_tokens || null, agent_max_steps: d.agent_max_steps || null })).put(updateTuning().url, { preserveScroll: true });

const prefs = useForm({ auto_analyze: props.settings.auto_analyze, share_contact: props.settings.share_contact, brand_context: props.settings.brand_context });
const savePrefs = () => prefs.submit(updateSettings(), { preserveScroll: true });

const status = (p: Provider) => {
    if (!p.configured) return { label: 'Sin configurar', cls: 'bg-muted text-muted-foreground' };
    if (!p.is_enabled) return { label: 'Deshabilitado', cls: 'bg-muted text-muted-foreground' };
    if (p.last_test_ok === false) return { label: 'Con error', cls: 'bg-destructive/10 text-destructive' };
    if (p.last_test_ok) return { label: 'Conectado', cls: 'bg-brand-green/10 text-brand-green' };
    return { label: 'Sin probar', cls: 'bg-[#FFA165]/20 text-[#9A4B00]' };
};
const total = computed(() => props.usage.reduce((a, u) => a + u.runs, 0));
</script>

<template>
    <Head title="Proveedores de IA" />

    <div class="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Proveedores de IA" description="Conecta el modelo que prefieras (Claude, OpenAI, OpenRouter, MiniMax…) para crear mailings y analizar leads.">
            <template #actions>
                <Badge :class="available ? 'border-transparent bg-brand-green/10 text-brand-green' : 'border-transparent bg-[#FFA165]/20 text-[#9A4B00]'">{{ available ? 'IA lista para usar' : 'Sin proveedor activo' }}</Badge>
            </template>
        </PageHeader>

        <p class="flex items-start gap-3 rounded-2xl border bg-card p-4 text-sm text-muted-foreground">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" />
            <span>Las API keys se guardan <strong class="text-foreground">cifradas</strong> en la base de datos, no se muestran de nuevo (solo los últimos 4 caracteres) y únicamente se envían al proveedor que elijas. Usa el SDK oficial de Laravel (<code>laravel/ai</code>).</span>
        </p>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="p in providers" :key="p.slug" class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]" :class="p.is_default && 'ring-2 ring-primary'">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white" :style="{ backgroundColor: p.color }"><Sparkles class="size-5" /></div>
                        <div class="min-w-0"><h3 class="truncate font-semibold">{{ p.name }}</h3><Badge :class="['mt-1 border-transparent', status(p).cls]">{{ status(p).label }}</Badge></div>
                    </div>
                    <Badge v-if="p.is_default" class="border-transparent bg-primary text-primary-foreground"><Star /> Por defecto</Badge>
                </div>
                <p class="min-h-10 text-sm text-muted-foreground">{{ p.description }}</p>

                <dl v-if="p.configured" class="grid gap-1.5 text-xs">
                    <div class="flex items-center justify-between gap-3"><dt class="flex items-center gap-1.5 text-muted-foreground"><Lock class="size-3" /> API key</dt><dd class="font-mono">{{ p.key_masked ?? '—' }}</dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-muted-foreground">Modelo</dt><dd class="max-w-[60%] truncate font-mono" :title="p.model ?? ''">{{ p.model ?? 'por defecto del SDK' }}</dd></div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="flex items-center gap-1.5 text-muted-foreground"><Mic class="size-3" /> Voz (micrófono)</dt>
                        <dd v-if="!p.stt.supported" class="text-muted-foreground" title="Claude, DeepSeek y xAI no transcriben audio">no compatible</dd>
                        <dd v-else class="max-w-[60%] truncate font-mono" :title="p.stt.effective ?? ''">{{ p.stt.effective ?? 'predeterminado' }}<Badge v-if="p.stt.in_use" variant="secondary" class="ml-1.5">en uso</Badge></dd>
                    </div>
                    <div v-if="p.last_tested_at" class="flex items-center justify-between gap-3"><dt class="text-muted-foreground">Última prueba</dt><dd>{{ timeAgo(p.last_tested_at) }}<template v-if="p.last_test_ms"> · {{ p.last_test_ms }} ms</template></dd></div>
                </dl>

                <p v-if="results[p.slug]" :class="cn('flex items-start gap-2 rounded-xl p-3 text-xs', results[p.slug].ok ? 'bg-brand-green/10 text-brand-green' : 'bg-destructive/10 text-destructive')">
                    <CheckCircle2 v-if="results[p.slug].ok" class="mt-0.5 size-4 shrink-0" /><CircleAlert v-else class="mt-0.5 size-4 shrink-0" />{{ results[p.slug].message }}<template v-if="results[p.slug].ms"> ({{ results[p.slug].ms }} ms)</template>
                </p>
                <p v-if="voiceResults[p.slug]" :class="cn('flex items-start gap-2 rounded-xl p-3 text-xs', voiceResults[p.slug].ok ? 'bg-brand-green/10 text-brand-green' : 'bg-destructive/10 text-destructive')">
                    <Mic class="mt-0.5 size-4 shrink-0" />{{ voiceResults[p.slug].message }}<template v-if="voiceResults[p.slug].ms"> ({{ voiceResults[p.slug].ms }} ms)</template>
                </p>
                <p v-else-if="p.last_test_ok === false && p.last_test_message" class="flex items-start gap-2 rounded-xl bg-destructive/10 p-3 text-xs text-destructive"><CircleAlert class="mt-0.5 size-4 shrink-0" />{{ p.last_test_message }}</p>

                <div class="mt-auto flex flex-wrap items-center gap-2 border-t pt-3">
                    <Button size="sm" :variant="p.configured ? 'outline' : 'default'" @click="open(p)"><KeyRound /> {{ p.configured ? 'Configurar' : 'Conectar' }}</Button>
                    <Button v-if="p.usable" size="sm" variant="outline" :disabled="testing[p.slug]" @click="runTest(p.slug)"><Spinner v-if="testing[p.slug]" /><Zap v-else /> Probar conexión</Button>
                    <Button v-if="p.usable && p.stt.supported" size="sm" variant="outline" :disabled="testingVoice[p.slug]" @click="runVoiceTest(p.slug)"><Spinner v-if="testingVoice[p.slug]" /><Mic v-else /> Probar voz</Button>
                    <Button v-if="p.usable && !p.is_default" size="sm" variant="ghost" @click="setDefault(p)"><Star /> Usar por defecto</Button>
                    <Button v-if="p.configured" size="icon-sm" variant="ghost" class="ml-auto text-destructive hover:text-destructive" title="Eliminar y borrar la key" @click="toDelete = p"><Trash2 /></Button>
                </div>
            </article>
        </div>

        <DataCard class="p-6">
            <h2 class="mb-1 font-semibold">Preferencias</h2>
            <form class="grid gap-5" @submit.prevent="savePrefs">
                <label class="flex items-start justify-between gap-4 text-sm"><span><span class="font-medium">Analizar clientes nuevos automáticamente</span><br /><span class="text-xs text-muted-foreground">Al ingresar un cliente se genera su perfil y recomendaciones en segundo plano (consume tokens del proveedor por defecto).</span></span><Switch :model-value="prefs.auto_analyze" @update:model-value="(v: boolean) => (prefs.auto_analyze = v)" /></label>
                <label class="flex items-start justify-between gap-4 text-sm"><span><span class="font-medium">Enviar correo y teléfono completos a la IA</span><br /><span class="text-xs text-muted-foreground">Desactivado: la IA solo recibe nombre, empresa, cargo, dominio del correo y datos comerciales (más privado). Actívalo solo si el proveedor está autorizado para tratar datos personales.</span></span><Switch :model-value="prefs.share_contact" @update:model-value="(v: boolean) => (prefs.share_contact = v)" /></label>
                <FormField label="Contexto de marca (lo que la IA sabe de MecaniQ)" for="bc" hint="Define la voz y los servicios. La IA lo usa al redactar mailings y propuestas de contacto.">
                    <Textarea id="bc" v-model="prefs.brand_context" rows="7" />
                </FormField>
                <div class="flex items-center justify-between">
                    <Button type="button" variant="ghost" size="sm" @click="router.put(updateSettings().url, { auto_analyze: prefs.auto_analyze, share_contact: prefs.share_contact, brand_context: '' }, { preserveScroll: true })"><RotateCcw /> Restablecer contexto de marca</Button>
                    <Button type="submit" :disabled="prefs.processing"><Spinner v-if="prefs.processing" /> Guardar preferencias</Button>
                </div>
            </form>
        </DataCard>

        <DataCard class="p-6">
            <h2 class="mb-1 flex items-center gap-2 font-semibold"><SlidersHorizontal class="size-4 text-primary" /> Comportamiento de la IA</h2>
            <p class="mb-5 text-sm text-muted-foreground">Ajusta cómo responde la IA en todo el CRM. Las reglas base de cada función (seguridad, herramientas y formato) siempre se mantienen; lo que escribas aquí se suma a ellas.</p>
            <form class="grid gap-5" @submit.prevent="saveTuning">
                <div class="grid gap-5 lg:grid-cols-2">
                    <FormField label="Instrucciones para toda la IA" for="ti" :error="tuning.errors.instructions" hint="Se aplican a propuestas, mailings, análisis de clientes y al Agent. Ej.: «Siempre habla de honorarios netos», «Firma como Catalina».">
                        <Textarea id="ti" v-model="tuning.instructions" rows="5" placeholder="Ej.: Usa siempre «usted» con empresas y «tú» con personas. Nunca prometas plazos municipales." />
                    </FormField>
                    <FormField label="Instrucciones solo para el Agent (chat)" for="ta" :error="tuning.errors.agent_instructions" hint="Cómo quieres que converse y actúe el asistente del chat.">
                        <Textarea id="ta" v-model="tuning.agent_instructions" rows="5" placeholder="Ej.: Responde corto y en viñetas. Antes de crear una propuesta, confirma el monto neto." />
                    </FormField>
                </div>
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Creatividad" for="tt" :error="tuning.errors.temperature" :hint="reasoningModel ? `El modelo ${defaultModel} no usa creatividad: ajusta el razonamiento.` : 'Precisa para datos y cobros; creativa para textos de marketing.'">
                        <NativeSelect id="tt" v-model="tuning.temperature" :disabled="reasoningModel"><option v-for="(label, k) in tuningOptions.temperatures" :key="k" :value="k">{{ label }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Razonamiento" for="tr" :error="tuning.errors.reasoning_effort" :hint="reasoningModel ? 'Más razonamiento = respuestas más pensadas, pero más lentas y caras.' : 'Solo para modelos de razonamiento de OpenAI (GPT-5, o3, o4-mini).'">
                        <NativeSelect id="tr" v-model="tuning.reasoning_effort" :disabled="!reasoningModel"><option v-for="(label, k) in tuningOptions.reasoning" :key="k" :value="k">{{ label }}</option></NativeSelect>
                    </FormField>
                    <FormField label="Largo máximo de respuesta" for="tm" :error="tuning.errors.max_tokens" hint="En tokens (≈ 0,75 palabras). Vacío = sin límite propio.">
                        <Input id="tm" v-model.number="tuning.max_tokens" type="number" min="256" max="32000" step="256" placeholder="Sin límite" />
                    </FormField>
                    <FormField label="Pasos máximos del Agent" for="ts" :error="tuning.errors.agent_max_steps" hint="Cuántas herramientas puede usar por mensaje (buscar, crear…). Vacío = predeterminado.">
                        <Input id="ts" v-model.number="tuning.agent_max_steps" type="number" min="2" max="30" placeholder="Predeterminado" />
                    </FormField>
                </div>
                <FormField label="Contexto para el dictado por voz" for="tv" :error="tuning.errors.transcription_prompt" hint="Palabras y nombres que suelen aparecer en tus audios (comunas, clientes, términos). Ayuda a que la transcripción en español de Chile salga bien. Vacío = texto por defecto.">
                    <Textarea id="tv" v-model="tuning.transcription_prompt" rows="2" placeholder="Ej.: Nota de voz en español de Chile. Comunas: Ñuñoa, Providencia, La Florida. Términos: DOM, recepción final, ampliación, UF." />
                </FormField>
                <div class="flex justify-end"><Button type="submit" :disabled="tuning.processing"><Spinner v-if="tuning.processing" /> Guardar comportamiento</Button></div>
            </form>
        </DataCard>

        <DataCard v-if="usage.length || recentErrors.length" class="p-6">
            <h2 class="mb-3 font-semibold">Uso de los últimos 30 días <span class="text-sm font-normal text-muted-foreground">({{ total }} llamadas)</span></h2>
            <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs text-muted-foreground uppercase"><tr><th class="py-2">Función</th><th class="text-right">Llamadas</th><th class="text-right">Tokens entrada</th><th class="text-right">Tokens salida</th><th class="text-right">Errores</th></tr></thead>
                <tbody class="divide-y"><tr v-for="u in usage" :key="u.feature"><td class="py-2">{{ featureLabel[u.feature] ?? u.feature }}</td><td class="text-right tabular-nums">{{ u.runs }}</td><td class="text-right tabular-nums">{{ Number(u.input_tokens).toLocaleString('es-CL') }}</td><td class="text-right tabular-nums">{{ Number(u.output_tokens).toLocaleString('es-CL') }}</td><td class="text-right tabular-nums" :class="u.errors ? 'text-destructive' : ''">{{ u.errors }}</td></tr></tbody></table></div>
            <ul v-if="recentErrors.length" class="mt-4 grid gap-1.5 text-xs text-muted-foreground"><li class="font-medium text-foreground">Últimos errores</li><li v-for="(e, i) in recentErrors" :key="i">{{ timeAgo(e.created_at) }} · {{ e.provider }} · {{ featureLabel[e.feature] ?? e.feature }}: {{ e.error }}</li></ul>
        </DataCard>
    </div>

    <Dialog :open="!!editing" @update:open="(v: boolean) => !v && (editing = null)">
        <DialogContent v-if="editing" class="sm:max-w-lg">
            <DialogHeader><DialogTitle>{{ editing.name }}</DialogTitle><DialogDescription>{{ editing.description }}</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="save(false)">
                <FormField label="API key" for="k" :error="form.errors.api_key" :hint="editing.has_key ? `Guardada (${editing.key_masked}). Déjalo vacío para conservarla o escribe una nueva para reemplazarla.` : `Formato: ${editing.key_hint_format}`">
                    <Input id="k" v-model="form.api_key" type="password" autocomplete="off" :placeholder="editing.has_key ? '••••••••••••' : 'Pega aquí tu API key'" />
                </FormField>
                <p v-if="editing.keys_url" class="-mt-2 text-xs"><a :href="editing.keys_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-primary hover:underline"><ExternalLink class="size-3" /> Obtener una API key</a></p>
                <FormField v-if="editing.url_editable" label="URL base" for="u" :error="form.errors.base_url" :required="editing.url_required" hint="Endpoint compatible con OpenAI (termina en /v1)."><Input id="u" v-model="form.base_url" type="url" :placeholder="editing.default_url || 'https://mi-gateway.com/v1'" /></FormField>
                <FormField label="Modelo" for="m" :error="form.errors.model" hint="Déjalo vacío para usar el modelo por defecto del SDK.">
                    <div class="flex gap-2">
                        <Input id="m" v-model="form.model" list="suggest" placeholder="ej: claude-sonnet-5-5" class="flex-1 font-mono text-xs" />
                        <datalist id="suggest"><option v-for="s in editing.suggest" :key="s" :value="s" /><option v-for="m in models" :key="m.id" :value="m.id" /></datalist>
                        <Button type="button" variant="outline" :disabled="loadingModels" @click="loadModels"><Spinner v-if="loadingModels" /><RefreshCw v-else /> Cargar modelos</Button>
                    </div>
                    <NativeSelect v-if="models.length" class="mt-2" :model-value="form.model" @update:model-value="(v) => (form.model = String(v))"><option value="">— elige un modelo ({{ models.length }}) —</option><option v-for="m in models" :key="m.id" :value="m.id">{{ m.id }}</option></NativeSelect>
                    <p v-if="modelsError" class="mt-1 text-xs text-destructive">{{ modelsError }}</p>
                </FormField>
                <FormField v-if="editing.stt.supported" label="Modelo de transcripción (micrófono del Agent)" for="tm" :error="form.errors.transcription_model" :hint="editing.stt.default ? `Vacío = ${editing.stt.default}. Es el modelo de voz a texto; distinto del modelo de chat.` : 'Vacío = el modelo de transcripción por defecto del proveedor. Es distinto del modelo de chat.'">
                    <Input id="tm" v-model="form.transcription_model" list="stt-suggest" :placeholder="editing.stt.suggest[0] ?? 'predeterminado del proveedor'" class="font-mono text-xs" />
                    <datalist id="stt-suggest"><option v-for="m in editing.stt.suggest" :key="m" :value="m" /></datalist>
                </FormField>
                <p v-else class="flex items-start gap-2 rounded-xl bg-muted p-3 text-xs text-muted-foreground"><Mic class="mt-0.5 size-4 shrink-0" /> {{ editing.name }} no transcribe audio. Para usar el micrófono del Agent conecta además OpenAI, Groq, Gemini o Mistral (se elige solo), o se usará el dictado del navegador.</p>
                <label class="flex items-center gap-3 text-sm"><Switch :model-value="form.is_enabled" @update:model-value="(v: boolean) => (form.is_enabled = v)" /> Habilitado</label>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="editing = null">Cancelar</Button><Button type="submit" variant="outline" :disabled="form.processing">Guardar</Button><Button type="button" :disabled="form.processing" @click="save(true)"><Plug /> Guardar y probar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toDelete" title="Eliminar proveedor" :description="`Se borrará la API key de «${toDelete?.name}». Las funciones de IA dejarán de usarlo.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="confirmDelete" />
</template>
