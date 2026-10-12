<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { ArrowLeft, Code, Eye, Layers, LayoutTemplate, Plug, Plus, Save, Send, Smartphone, Monitor, Sparkles, Wand2 } from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AiMailAssistant from '@/components/email/AiMailAssistant.vue';
import type { AiDesignResult } from '@/components/email/AiMailAssistant.vue';
import ApiSnippets from '@/components/email/ApiSnippets.vue';
import BlockList from '@/components/email/BlockList.vue';
import BlockPalette from '@/components/email/BlockPalette.vue';
import BlockProps from '@/components/email/BlockProps.vue';
import ColorField from '@/components/email/ColorField.vue';
import PreviewFrame from '@/components/email/PreviewFrame.vue';
import TestSendDialog from '@/components/email/TestSendDialog.vue';
import VariableField from '@/components/email/VariableField.vue';
import VariablesPanel from '@/components/email/VariablesPanel.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { BLOCKS, FONTS, compileDesign, emptyDesign, newBlock } from '@/lib/emailBuilder';
import type { Block, BlockType, Design } from '@/lib/emailBuilder';
import { starters } from '@/lib/emailStarters';
import { HttpError, sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { index as aiIndex } from '@/routes/ai';
import { edit, index, preview, store, update } from '@/routes/templates';
import type { VarMeta } from '@/types';

type TemplateData = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    category: string;
    subject: string;
    preheader: string | null;
    editor: 'blocks' | 'html';
    html: string;
    design: Design | null;
    variables: { key: string; label: string; default: string | null; sample: string | null }[] | null;
    is_active: boolean;
};

const props = defineProps<{
    template: TemplateData | null;
    categories: Record<string, string>;
    systemVariables: { key: string; description: string }[];
    brand: { name: string; address: string; logo: string };
    ai: { enabled: boolean; can_configure: boolean };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Plantillas', href: index() }] } });

const page = usePage();
const t = props.template;

// ---------------------------------------------------------------- estado
const started = ref(!!t);
const saving = ref(false);
const errorMsg = ref('');
const savedId = ref<number | null>(t?.id ?? null);
const savedSlug = ref(t?.slug ?? '');

const form = reactive({
    name: t?.name ?? '',
    slug: t?.slug ?? '',
    description: t?.description ?? '',
    category: t?.category ?? 'marketing',
    subject: t?.subject ?? '',
    preheader: t?.preheader ?? '',
    editor: (t?.editor ?? 'blocks') as 'blocks' | 'html',
    is_active: t?.is_active ?? true,
});
const design = ref<Design>(t?.design ?? emptyDesign());
const code = ref(t?.editor === 'html' ? (t?.html ?? '') : '');
const varMeta = ref<Record<string, VarMeta>>(
    Object.fromEntries((t?.variables ?? []).map((v) => [v.key, { label: v.label, default: v.default ?? '', sample: v.sample ?? '' }])),
);
const detected = ref<string[]>((t?.variables ?? []).map((v) => v.key));

const selectedId = ref<string | null>(null);
const device = ref<'desktop' | 'mobile'>('desktop');
const leftTab = ref('add');
const rightTab = ref('props');
const testOpen = ref(false);
const apiOpen = ref(false);
const aiOpen = ref(false);
const subjectIdeas = ref<string[]>([]);
const subjectBusy = ref(false);

const selected = computed(() => design.value.blocks.find((b) => b.id === selectedId.value) ?? null);
const systemKeys = computed(() => props.systemVariables.map((s) => s.key));
const blockMode = computed(() => form.editor === 'blocks');

// ---------------------------------------------------------------- HTML compilado
const htmlPreview = computed(() => (blockMode.value ? compileDesign(design.value, { editor: true }) : code.value));
const htmlFinal = computed(() => (blockMode.value ? compileDesign(design.value) : code.value));
const sample = computed(() => Object.fromEntries(Object.entries(varMeta.value).map(([k, v]) => [k, v.sample || v.default || ''])));

const rendered = ref('');
const missing = ref<string[]>([]);
let timer: ReturnType<typeof setTimeout>;
const renderPreview = async () => {
    try {
        const res = await sendJson<{ html: string; detected: string[]; missing: string[] }>('POST', preview().url, {
            subject: form.subject,
            html: htmlPreview.value,
            preheader: form.preheader,
            variables: sample.value,
        });
        rendered.value = res.html;
        detected.value = res.detected;
        missing.value = res.missing;
    } catch {
        /* la vista previa se reintenta con el siguiente cambio */
    }
};
watch([htmlPreview, () => form.subject, () => form.preheader, sample], () => {
    clearTimeout(timer);
    timer = setTimeout(renderPreview, 350);
}, { deep: true, immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

// ---------------------------------------------------------------- bloques
const addBlock = (type: BlockType) => {
    const block = newBlock(type);
    if (type === 'header') block.props.logoUrl = props.brand.logo;
    const i = design.value.blocks.findIndex((b) => b.id === selectedId.value);
    design.value.blocks.splice(i >= 0 ? i + 1 : design.value.blocks.length, 0, block);
    selectedId.value = block.id;
    rightTab.value = 'props';
    leftTab.value = 'structure';
};
const duplicateBlock = (b: Block) => {
    const copy: Block = { id: newBlock('spacer').id, type: b.type, props: JSON.parse(JSON.stringify(b.props)) };
    const i = design.value.blocks.findIndex((x) => x.id === b.id);
    design.value.blocks.splice(i + 1, 0, copy);
    selectedId.value = copy.id;
};
const removeBlock = (b: Block) => {
    design.value.blocks = design.value.blocks.filter((x) => x.id !== b.id);
    if (selectedId.value === b.id) selectedId.value = null;
};
const selectBlock = (id: string) => {
    selectedId.value = id;
    rightTab.value = 'props';
};

// ---------------------------------------------------------------- IA (opcional)
const applyAi = (r: AiDesignResult) => {
    design.value = r.design;
    form.subject = r.subject || form.subject;
    form.preheader = r.preheader || form.preheader;
    form.name = form.name || r.subject || 'Correo generado con IA';
    form.editor = 'blocks';
    started.value = true;
    selectedId.value = null;
    leftTab.value = 'structure';
    toast.success('Correo generado con la identidad de INTEGRALEADS', { description: r.notes || 'Revisa el contenido antes de enviarlo.' });
};
const plainContent = () =>
    design.value.blocks
        .flatMap((b) => [b.props.text, b.props.title, b.props.label])
        .filter((x) => typeof x === 'string' && x)
        .join('\n');
const suggestSubjects = async () => {
    subjectBusy.value = true;
    subjectIdeas.value = [];
    try {
        const res = await sendJson<{ subjects: string[] }>('POST', '/email/ai/subjects', { subject: form.subject, context: blockMode.value ? plainContent() : code.value.replace(/<[^>]+>/g, ' ').slice(0, 5000) });
        subjectIdeas.value = res.subjects;
        if (!res.subjects.length) toast.info('La IA no devolvió propuestas. Intenta de nuevo.');
    } catch (e) {
        toast.error(e instanceof HttpError ? (Object.values(e.fieldErrors)[0] ?? e.body?.message ?? 'No se pudieron generar asuntos.') : 'No se pudieron generar asuntos.');
    } finally {
        subjectBusy.value = false;
    }
};

// ---------------------------------------------------------------- modo
const switchToHtml = () => {
    code.value = compileDesign(design.value);
    form.editor = 'html';
    selectedId.value = null;
};
const switchToBlocks = () => {
    if (code.value !== compileDesign(design.value) && !window.confirm('Al volver al editor visual se descartan los cambios hechos directamente en el HTML. ¿Continuar?')) return;
    form.editor = 'blocks';
};

// ---------------------------------------------------------------- inicio con plantilla base
const pickStarter = (key: string) => {
    const s = starters.find((x) => x.key === key)!;
    design.value = JSON.parse(JSON.stringify(s.design));
    design.value.blocks.filter((b) => b.type === 'header' && !b.props.logoUrl).forEach((b) => (b.props.logoUrl = props.brand.logo));
    form.name = form.name || s.name;
    form.subject = s.subject;
    form.preheader = s.preheader;
    form.category = s.category;
    form.editor = 'blocks';
    started.value = true;
    leftTab.value = 'structure';
};
const startFromHtml = () => {
    form.editor = 'html';
    code.value = '<!doctype html>\n<html>\n<body style="margin:0;background:#f4f4f4;font-family:Arial,sans-serif">\n  <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">\n    <table width="600" style="max-width:100%;background:#fff;border-radius:16px"><tr><td style="padding:32px">\n      <h1 style="margin:0 0 12px">Hola {{ first_name | default:"" }}</h1>\n      <p>Tu mensaje aquí.</p>\n      <p style="font-size:12px;color:#8a8a8a"><a href="{{ unsubscribe_url }}">Darme de baja</a></p>\n    </td></tr></table>\n  </td></tr></table>\n</body>\n</html>';
    started.value = true;
};

// ---------------------------------------------------------------- guardado
const snapshot = () => JSON.stringify([form, design.value, code.value, varMeta.value]);
const baseline = ref(snapshot());
const dirty = computed(() => snapshot() !== baseline.value);

const save = async () => {
    saving.value = true;
    errorMsg.value = '';
    const payload = {
        ...form,
        slug: form.slug || undefined,
        html: htmlFinal.value,
        design: design.value,
        variables: detected.value.map((k) => ({ key: k, label: varMeta.value[k]?.label || k, default: varMeta.value[k]?.default || null, sample: varMeta.value[k]?.sample || null })),
    };
    try {
        if (savedId.value) {
            const res = await sendJson<{ slug: string; message: string }>('PUT', update(savedId.value).url, payload);
            savedSlug.value = res.slug;
            form.slug = res.slug;
            baseline.value = snapshot();
            toast.success(res.message);
        } else {
            const res = await sendJson<{ id: number }>('POST', store().url, payload);
            baseline.value = snapshot();
            toast.success('Plantilla creada');
            router.visit(edit(res.id).url, { replace: true });
        }
    } catch (e) {
        if (e instanceof HttpError) {
            const first = Object.values(e.fieldErrors)[0];
            errorMsg.value = first ?? e.body?.message ?? 'No se pudo guardar.';
        } else {
            errorMsg.value = 'No se pudo guardar.';
        }
        toast.error(errorMsg.value);
    } finally {
        saving.value = false;
    }
};

useEventListener(window, 'keydown', (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        if (started.value) save();
    }
});
useEventListener(window, 'beforeunload', (e: BeforeUnloadEvent) => {
    if (dirty.value) e.preventDefault();
});

const testPayload = () => ({ subject: form.subject, html: htmlFinal.value, preheader: form.preheader, variables: sample.value, template_id: savedId.value });
const apiEndpoint = computed(() => `${window.location.origin}/api/v1`);
</script>

<template>
    <Head :title="form.name || 'Nueva plantilla'" />

    <!-- Selector de punto de partida (plantilla nueva) -->
    <div v-if="!started" class="mx-auto flex max-w-5xl flex-col gap-6 p-4 md:p-8">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Nueva plantilla de email</h1>
            <p class="mt-1 text-sm text-muted-foreground">Elige un punto de partida. Todo se puede cambiar después.</p>
        </div>
        <button v-if="ai.enabled" type="button" class="flex items-center gap-4 rounded-2xl border border-primary/40 bg-primary/5 p-5 text-left transition hover:-translate-y-0.5 hover:border-primary hover:shadow-md" @click="aiOpen = true">
            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground"><Sparkles class="size-5" /></span>
            <span><span class="block font-semibold">Crear con IA <span class="ml-1 rounded-full bg-primary/15 px-2 py-0.5 text-[11px] font-medium text-primary">Opcional</span></span><span class="text-sm text-muted-foreground">Describe el correo y la IA redacta asunto y contenido. El diseño siempre respeta la marca INTEGRALEADS y usa el logo oficial.</span></span>
        </button>
        <p v-else-if="ai.can_configure" class="rounded-2xl border border-dashed p-4 text-sm text-muted-foreground"><Sparkles class="mr-1 inline size-4 text-primary" />¿Quieres redactar con IA? <Link :href="aiIndex()" class="font-medium text-primary underline-offset-2 hover:underline">Configura un proveedor</Link>.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <button v-for="s in starters" :key="s.key" type="button" class="group flex flex-col gap-2 rounded-2xl border bg-card p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-md" @click="pickStarter(s.key)">
                <div class="flex items-center gap-2">
                    <LayoutTemplate class="size-5 text-primary" />
                    <h3 class="font-semibold">{{ s.name }}</h3>
                </div>
                <p class="text-sm text-muted-foreground">{{ s.description }}</p>
                <span class="mt-1 w-fit rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">{{ categories[s.category] }}</span>
            </button>
            <button type="button" class="flex flex-col gap-2 rounded-2xl border border-dashed bg-card p-5 text-left transition hover:border-primary/50" @click="startFromHtml">
                <div class="flex items-center gap-2"><Code class="size-5 text-primary" /><h3 class="font-semibold">Pegar mi propio HTML</h3></div>
                <p class="text-sm text-muted-foreground">Ya tienes el diseño en HTML: pégalo, usa variables <code v-pre>{{ nombre }}</code> y listo.</p>
            </button>
        </div>
        <Button variant="ghost" class="w-fit" as-child><Link :href="index()"><ArrowLeft /> Volver a plantillas</Link></Button>
    </div>

    <!-- Editor -->
    <div v-else class="flex h-[calc(100svh-4.5rem)] min-h-[560px] flex-col">
        <!-- Barra superior -->
        <div class="flex flex-wrap items-center gap-2 border-b bg-card px-3 py-2">
            <Button variant="ghost" size="icon-sm" as-child title="Volver"><Link :href="index()"><ArrowLeft /></Link></Button>
            <Input v-model="form.name" placeholder="Nombre de la plantilla" class="h-9 w-56 font-semibold" />
            <span v-if="dirty" class="rounded-full bg-[#FFA165]/20 px-2 py-0.5 text-[11px] font-medium text-[#9A4B00]">Cambios sin guardar</span>
            <span v-else-if="savedId" class="text-[11px] text-muted-foreground">Guardado</span>

            <div class="mx-auto flex items-center gap-1 rounded-full bg-muted p-0.5 text-sm">
                <button type="button" :class="cn('flex items-center gap-1.5 rounded-full px-3 py-1 transition', blockMode ? 'bg-background font-medium text-primary shadow-sm' : 'text-muted-foreground')" @click="!blockMode && switchToBlocks()"><Layers class="size-4" /> Visual</button>
                <button type="button" :class="cn('flex items-center gap-1.5 rounded-full px-3 py-1 transition', !blockMode ? 'bg-background font-medium text-primary shadow-sm' : 'text-muted-foreground')" @click="blockMode && switchToHtml()"><Code class="size-4" /> HTML</button>
            </div>

            <div class="flex items-center gap-1">
                <Button variant="ghost" size="icon-sm" :class="device === 'desktop' && 'bg-accent text-accent-foreground'" title="Escritorio" @click="device = 'desktop'"><Monitor /></Button>
                <Button variant="ghost" size="icon-sm" :class="device === 'mobile' && 'bg-accent text-accent-foreground'" title="Móvil" @click="device = 'mobile'"><Smartphone /></Button>
            </div>
            <Button v-if="ai.enabled" variant="outline" size="sm" class="border-primary/40 text-primary" @click="aiOpen = true"><Sparkles /> IA</Button>
            <Button v-if="savedId" variant="outline" size="sm" @click="apiOpen = true"><Plug /> API</Button>
            <Button variant="outline" size="sm" @click="testOpen = true"><Send /> Probar</Button>
            <Button size="sm" :disabled="saving || !form.name || !form.subject || !htmlFinal.trim()" @click="save"><Spinner v-if="saving" /><Save v-else /> Guardar</Button>
        </div>

        <!-- Asunto + preheader -->
        <div class="grid gap-2 border-b bg-muted/30 px-3 py-2 md:grid-cols-2">
            <div class="relative flex items-center gap-2"><label class="w-16 shrink-0 text-xs font-medium text-muted-foreground">Asunto</label><VariableField v-model="form.subject" class="flex-1" :variables="detected" :system="systemKeys" placeholder="Ej: Hola {{ first_name }}, gracias por escribirnos" />
                <Button v-if="ai.enabled" variant="ghost" size="icon-sm" title="Sugerir asuntos con IA" :disabled="subjectBusy" @click="suggestSubjects"><Spinner v-if="subjectBusy" /><Sparkles v-else class="text-primary" /></Button>
                <div v-if="subjectIdeas.length" class="absolute right-0 top-full z-30 mt-1 w-full max-w-xl rounded-2xl border bg-popover p-2 shadow-lg">
                    <div class="flex items-center justify-between px-2 pb-1 text-[11px] text-muted-foreground"><span>Propuestas de la IA (clic para usar)</span><button type="button" class="hover:text-foreground" @click="subjectIdeas = []">Cerrar</button></div>
                    <button v-for="(idea, i) in subjectIdeas" :key="i" type="button" class="block w-full rounded-xl px-2 py-1.5 text-left text-sm hover:bg-accent" @click="form.subject = idea; subjectIdeas = []">{{ idea }}</button>
                </div>
            </div>
            <div class="flex items-center gap-2"><label class="w-16 shrink-0 text-xs font-medium text-muted-foreground">Preheader</label><VariableField v-model="form.preheader" class="flex-1" :variables="detected" :system="systemKeys" placeholder="Texto que se ve junto al asunto en la bandeja" /></div>
        </div>
        <p v-if="errorMsg" class="border-b bg-destructive/10 px-3 py-1.5 text-xs text-destructive">{{ errorMsg }}</p>

        <div class="flex min-h-0 flex-1">
            <!-- Izquierda: bloques -->
            <aside v-if="blockMode" class="w-[270px] shrink-0 overflow-y-auto border-r bg-card p-3">
                <Tabs v-model="leftTab">
                    <TabsList class="w-full"><TabsTrigger value="add" class="flex-1"><Plus /> Agregar</TabsTrigger><TabsTrigger value="structure" class="flex-1"><Layers /> Estructura</TabsTrigger></TabsList>
                    <TabsContent value="add" class="mt-3"><BlockPalette @add="addBlock" /><p class="mt-3 text-[11px] text-muted-foreground">El bloque se agrega debajo del seleccionado.</p></TabsContent>
                    <TabsContent value="structure" class="mt-3">
                        <BlockList v-model="design.blocks" :selected-id="selectedId" @select="selectBlock" @duplicate="duplicateBlock" @remove="removeBlock" />
                        <p v-if="!design.blocks.length" class="rounded-xl border border-dashed p-4 text-center text-xs text-muted-foreground">Sin bloques. Agrega el primero desde «Agregar».</p>
                    </TabsContent>
                </Tabs>
            </aside>

            <!-- Centro: vista previa (y código en modo HTML) -->
            <main class="flex min-w-0 flex-1">
                <div v-if="!blockMode" class="flex w-1/2 min-w-0 flex-col border-r">
                    <div class="flex items-center justify-between border-b px-3 py-1.5 text-xs text-muted-foreground"><span>Código HTML</span><span>{{ code.length.toLocaleString('es-CL') }} caracteres</span></div>
                    <textarea v-model="code" spellcheck="false" class="min-h-0 flex-1 resize-none bg-[#1e1e1e] p-4 font-mono text-xs leading-relaxed text-[#e6e6e6] outline-none" />
                </div>
                <div class="min-w-0 flex-1">
                    <PreviewFrame :html="rendered" :device="device" :selected-id="selectedId" :selectable="blockMode" @select="selectBlock" />
                </div>
            </main>

            <!-- Derecha: propiedades -->
            <aside class="w-[320px] shrink-0 overflow-y-auto border-l bg-card p-3">
                <Tabs v-model="rightTab">
                    <TabsList class="w-full">
                        <TabsTrigger v-if="blockMode" value="props" class="flex-1">Bloque</TabsTrigger>
                        <TabsTrigger v-if="blockMode" value="style" class="flex-1"><Wand2 /> Estilo</TabsTrigger>
                        <TabsTrigger value="vars" class="flex-1">Variables</TabsTrigger>
                        <TabsTrigger value="settings" class="flex-1">Ajustes</TabsTrigger>
                    </TabsList>

                    <TabsContent value="props" class="mt-4">
                        <template v-if="selected">
                            <div class="mb-3 flex items-center justify-between"><h3 class="text-sm font-semibold">{{ BLOCKS[selected.type].label }}</h3><span class="text-[11px] text-muted-foreground">{{ BLOCKS[selected.type].description }}</span></div>
                            <BlockProps :block="selected" :variables="detected" :system="systemKeys" />
                        </template>
                        <p v-else class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"><Eye class="mx-auto mb-2 size-5" />Haz clic en un bloque de la vista previa o de la estructura para editarlo.</p>
                    </TabsContent>

                    <TabsContent value="style" class="mt-4 grid gap-4">
                        <FormField label="Fondo general"><ColorField v-model="design.settings.bg" /></FormField>
                        <FormField label="Fondo del contenido"><ColorField v-model="design.settings.contentBg" /></FormField>
                        <FormField label="Color del texto"><ColorField v-model="design.settings.textColor" /></FormField>
                        <FormField label="Color de enlaces"><ColorField v-model="design.settings.linkColor" /></FormField>
                        <FormField label="Tipografía" for="font"><NativeSelect id="font" v-model="design.settings.font"><option v-for="f in FONTS" :key="f.value" :value="f.value">{{ f.label }}</option></NativeSelect></FormField>
                        <FormField label="Ancho (px)" for="w"><Input id="w" v-model.number="design.settings.width" type="number" min="480" max="720" /></FormField>
                        <FormField label="Bordes redondeados (px)" for="r"><Input id="r" v-model.number="design.settings.radius" type="number" min="0" max="32" /></FormField>
                        <p class="text-[11px] text-muted-foreground">Los clientes de correo solo garantizan fuentes seguras (Arial, Georgia, Verdana…). Por eso no se usan fuentes web.</p>
                    </TabsContent>

                    <TabsContent value="vars" class="mt-4"><VariablesPanel v-model="varMeta" :detected="detected" :system="systemVariables" /></TabsContent>

                    <TabsContent value="settings" class="mt-4 grid gap-4">
                        <FormField label="Identificador (slug)" for="slug" hint="Es el nombre que usas en la API: &quot;template&quot;: &quot;…&quot;.">
                            <Input id="slug" v-model="form.slug" placeholder="se genera desde el nombre" class="font-mono text-xs" />
                        </FormField>
                        <FormField label="Tipo" for="cat">
                            <NativeSelect id="cat" v-model="form.category"><option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option></NativeSelect>
                        </FormField>
                        <p class="-mt-2 text-[11px] text-muted-foreground"><strong>Marketing</strong> agrega enlace de baja y cabeceras List-Unsubscribe. <strong>Transaccional</strong> es para avisos individuales (bienvenida, cotización).</p>
                        <FormField label="Descripción interna" for="desc"><Textarea id="desc" v-model="form.description" rows="2" /></FormField>
                        <label class="flex items-center justify-between text-sm">Plantilla activa<Switch :model-value="form.is_active" @update:model-value="(v: boolean) => (form.is_active = v)" /></label>
                        <div v-if="missing.length" class="rounded-xl bg-[#FFA165]/10 p-3 text-xs text-[#7A3A00]">Variables sin valor en la vista previa: <strong>{{ missing.join(', ') }}</strong>. Agrega un valor de ejemplo en «Variables».</div>
                    </TabsContent>
                </Tabs>
            </aside>
        </div>
    </div>

    <AiMailAssistant v-model:open="aiOpen" :has-content="started && design.blocks.length > 0" @apply="applyAi" />
    <TestSendDialog v-model:open="testOpen" :default-to="page.props.auth.user.email" :payload="testPayload" />

    <Dialog v-model:open="apiOpen">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Usar «{{ form.name }}» por API</DialogTitle>
                <DialogDescription>Envía esta plantilla desde una landing, un formulario o una automatización. Las variables detectadas ya vienen en el ejemplo.</DialogDescription>
            </DialogHeader>
            <ApiSnippets :slug="savedSlug || form.slug" :variables="detected" :sample="sample" :endpoint="apiEndpoint" />
        </DialogContent>
    </Dialog>
</template>
