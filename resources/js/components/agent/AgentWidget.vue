<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ArrowUp, Check, ExternalLink, TriangleAlert, FileText, Maximize2, Mic, Minimize2, Paperclip, Sparkles, Square, Trash2, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { HttpError, sendJson } from '@/lib/http';
import { renderMarkdown } from '@/lib/miniMarkdown';
import { cn } from '@/lib/utils';

type Action = { tool: string; label: string; url: string | null; ok: boolean };
type Attachment = { name: string; text?: string; chars?: number; truncated?: boolean };
type Approval = { id: string; title: string; lines: string[]; destructive: boolean; op: string; state?: 'waiting' | 'working' | 'done' | 'cancelled' | 'error'; result?: string; url?: string | null };
type Msg = { role: 'user' | 'assistant'; content: string; actions?: Action[]; approvals?: Approval[]; attachments?: Attachment[]; error?: boolean };
type Pending = { id: number; name: string; status: 'loading' | 'ok' | 'error'; text?: string; chars?: number; truncated?: boolean; error?: string };

const page = usePage();
const uid = computed(() => (page.props.auth as { user?: { id?: number } } | undefined)?.user?.id ?? 'u');
const storeKey = computed(() => `crm-agent:${uid.value}`);
const focusKey = computed(() => `crm-agent-focus:${uid.value}`);

const open = ref(false);
const focus = ref(false);
const input = ref('');
const busy = ref(false);
const messages = ref<Msg[]>([]);
const pending = ref<Pending[]>([]);
const status = ref<{ available: boolean; transcription: boolean; extensions: string[] } | null>(null);
const dragging = ref(false);
const scroller = ref<HTMLElement | null>(null);
const field = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
let pid = 0;

const suggestions = [
    'Resume cómo vamos: prospectos por etapa y propuestas por estado',
    '¿En qué estado está la última propuesta que creé?',
    'Crea la propuesta con este texto: ',
    'Registra un seguimiento: llamé a … y quedamos en hablar el viernes',
];

const ls = {
    get: (k: string) => {
        try {
            return localStorage.getItem(k);
        } catch {
            return null;
        }
    },
    set: (k: string, v: string) => {
        try {
            localStorage.setItem(k, v);
        } catch {
            /* sin almacenamiento */
        }
    },
};

onMounted(() => {
    try {
        messages.value = JSON.parse(ls.get(storeKey.value) ?? '[]');
    } catch {
        messages.value = [];
    }
    focus.value = ls.get(focusKey.value) === '1';
    window.addEventListener('keydown', onWindowKey);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onWindowKey);
    stopRecording(true);
});

// Se guarda sin el texto de los archivos (pesado): solo sus nombres.
watch(
    messages,
    (m) => ls.set(storeKey.value, JSON.stringify(m.slice(-30).map((x) => ({ ...x, attachments: x.attachments?.map(({ name, chars }) => ({ name, chars })) })))),
    { deep: true },
);
watch(focus, (v) => {
    ls.set(focusKey.value, v ? '1' : '0');
    nextTick(() => {
        scrollDown(false);
        field.value?.focus();
    });
});

const scrollDown = (smooth = true) => nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }));

const loadStatus = async () => {
    if (status.value) return;
    try {
        status.value = await sendJson('GET', '/agent/status');
    } catch {
        status.value = { available: true, transcription: false, extensions: ['pdf', 'docx', 'xlsx', 'txt', 'csv', 'md', 'json'] };
    }
};

const toggle = async () => {
    open.value = !open.value;
    if (open.value) {
        scrollDown(false);
        nextTick(() => field.value?.focus());
        loadStatus();
    } else {
        stopRecording(true);
    }
};

const onWindowKey = (e: KeyboardEvent) => {
    if (e.key !== 'Escape' || !open.value) return;
    if (focus.value) focus.value = false;
};

const resize = () => {
    const el = field.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, focus.value ? 260 : 160) + 'px';
};

// ---------------------------------------------------------------- archivos
const accept = computed(() => (status.value?.extensions ?? ['pdf', 'docx', 'xlsx', 'txt', 'csv']).map((e) => '.' + e).join(','));

// Se muta el elemento reactivo (no el objeto original) para que la interfaz se entere.
const patch = (id: number, data: Partial<Pending>) => {
    const it = pending.value.find((p) => p.id === id);
    if (it) Object.assign(it, data);
};

const addFiles = async (list: FileList | File[] | null) => {
    const files = Array.from(list ?? []);
    if (!files.length) return;
    const room = 5 - pending.value.length;
    if (room <= 0) return;
    const batch = files.slice(0, room);
    const items: Pending[] = batch.map((f) => ({ id: ++pid, name: f.name, status: 'loading' }));
    pending.value.push(...items);

    const body = new FormData();
    batch.forEach((f) => body.append('files[]', f));
    try {
        const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1];
        const res = await fetch('/agent/attachments', {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) },
        });
        const json = await res.json().catch(() => null);
        if (!res.ok) throw new Error(json?.errors ? (Object.values(json.errors).flat()[0] as string) : (json?.message ?? `Error ${res.status}`));
        (json.files as { ok: boolean; name: string; text?: string; chars?: number; truncated?: boolean; error?: string }[]).forEach((f, i) => {
            patch(items[i].id, f.ok ? { status: 'ok', text: f.text, chars: f.chars, truncated: f.truncated, name: f.name } : { status: 'error', error: f.error });
        });
    } catch (e) {
        items.forEach((it) => patch(it.id, { status: 'error', error: e instanceof Error ? e.message : 'No se pudo subir el archivo.' }));
    }
};
const onPick = (e: Event) => {
    const el = e.target as HTMLInputElement;
    addFiles(el.files);
    el.value = '';
};
const onPaste = (e: ClipboardEvent) => {
    const files = Array.from(e.clipboardData?.files ?? []);
    if (files.length) {
        e.preventDefault();
        addFiles(files);
    }
};
const onDrop = (e: DragEvent) => {
    dragging.value = false;
    addFiles(e.dataTransfer?.files ?? null);
};
const removePending = (id: number) => (pending.value = pending.value.filter((p) => p.id !== id));
const uploading = computed(() => pending.value.some((p) => p.status === 'loading'));
const fmtChars = (n?: number) => (n ? (n >= 1000 ? `${Math.round(n / 1000)}k car.` : `${n} car.`) : '');

// ---------------------------------------------------------------- voz
type SpeechEv = { results: ArrayLike<ArrayLike<{ transcript: string }>> };
type Rec = {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    onresult: ((ev: SpeechEv) => void) | null;
    onerror: ((ev: { error: string }) => void) | null;
    onend: (() => void) | null;
    start: () => void;
    stop: () => void;
};
const recording = ref(false);
const transcribing = ref(false);
const seconds = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;
let recorder: MediaRecorder | null = null;
let stream: MediaStream | null = null;
let chunks: Blob[] = [];
let speech: Rec | null = null;
let speechBase = '';
let discard = false;

const SpeechCtor = typeof window !== 'undefined' ? ((window as unknown as { SpeechRecognition?: new () => Rec; webkitSpeechRecognition?: new () => Rec }).SpeechRecognition ?? (window as unknown as { webkitSpeechRecognition?: new () => Rec }).webkitSpeechRecognition) : undefined;
const canRecord = computed(() => !!status.value && (status.value.transcription ? typeof MediaRecorder !== 'undefined' && !!navigator.mediaDevices?.getUserMedia : !!SpeechCtor));
const mm = computed(() => `${Math.floor(seconds.value / 60)}:${String(seconds.value % 60).padStart(2, '0')}`);

const startTimer = () => {
    seconds.value = 0;
    timer = setInterval(() => {
        seconds.value++;
        if (seconds.value >= 300) stopRecording(); // máximo 5 min
    }, 1000);
};

const micError = (msg: string) => messages.value.push({ role: 'assistant', content: msg, error: true });

const toggleMic = async () => {
    if (recording.value) return stopRecording();
    if (transcribing.value || busy.value) return;
    discard = false;
    try {
        if (status.value?.transcription) {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find((m) => MediaRecorder.isTypeSupported(m));
            recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
            chunks = [];
            recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
            recorder.onstop = onRecorded;
            recorder.start();
        } else if (SpeechCtor) {
            speech = new SpeechCtor();
            speech.lang = 'es-CL';
            speech.continuous = true;
            speech.interimResults = true;
            speechBase = input.value ? input.value.trimEnd() + ' ' : '';
            speech.onresult = (ev) => {
                let t = '';
                for (let i = 0; i < ev.results.length; i++) t += ev.results[i][0].transcript;
                input.value = speechBase + t;
                nextTick(resize);
            };
            speech.onerror = (ev) => {
                if (ev.error === 'not-allowed') micError('Permite el acceso al micrófono en tu navegador para dictar.');
                stopRecording(true);
            };
            speech.onend = () => {
                if (recording.value) finishSpeech();
            };
            speech.start();
        }
        recording.value = true;
        startTimer();
    } catch {
        micError('No pude acceder al micrófono. Revisa el permiso del navegador.');
        cleanupMedia();
    }
};

const cleanupMedia = () => {
    clearInterval(timer);
    recording.value = false;
    stream?.getTracks().forEach((t) => t.stop());
    stream = null;
    recorder = null;
    speech = null;
};

const stopRecording = (silent = false) => {
    if (!recording.value) return;
    discard = silent;
    if (recorder && recorder.state !== 'inactive') recorder.stop();
    else if (speech) {
        const s = speech;
        finishSpeech();
        s.stop();
    }
};

const finishSpeech = () => {
    const text = input.value.trim();
    cleanupMedia();
    if (!discard && text) send();
};

const onRecorded = async () => {
    const blob = new Blob(chunks, { type: recorder?.mimeType || 'audio/webm' });
    cleanupMedia();
    if (discard || blob.size < 800) return;
    transcribing.value = true;
    try {
        const body = new FormData();
        const ext = blob.type.includes('mp4') ? 'm4a' : blob.type.includes('ogg') ? 'ogg' : 'webm';
        body.append('audio', blob, `nota-de-voz.${ext}`);
        const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1];
        const res = await fetch('/agent/transcribe', {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}) },
        });
        const json = await res.json().catch(() => null);
        if (!res.ok) throw new Error(json?.message ?? `Error ${res.status}`);
        const text = String(json.text ?? '').trim();
        if (!text) throw new Error('No se entendió el audio. Intenta de nuevo.');
        input.value = (input.value ? input.value.trimEnd() + ' ' : '') + text;
        nextTick(resize);
        send();
    } catch (e) {
        micError(e instanceof Error ? e.message : 'No se pudo transcribir el audio.');
    } finally {
        transcribing.value = false;
    }
};

// ---------------------------------------------------------------- envío
const canSend = computed(() => !busy.value && !uploading.value && !recording.value && !transcribing.value && (input.value.trim() !== '' || pending.value.some((p) => p.status === 'ok')) && status.value?.available !== false);

const send = async (text?: string) => {
    const content = (text ?? input.value).trim();
    const files = pending.value.filter((p) => p.status === 'ok');
    if ((!content && !files.length) || busy.value || uploading.value) return;

    const attachments: Attachment[] = files.map((f) => ({ name: f.name, text: f.text, chars: f.chars, truncated: f.truncated }));
    messages.value.push({ role: 'user', content: content || 'Revisa los archivos adjuntos y dime qué hacer con ellos.', attachments: attachments.length ? attachments : undefined });
    input.value = '';
    pending.value = [];
    nextTick(resize);
    busy.value = true;
    scrollDown();

    // Solo los dos últimos mensajes con archivos conservan su texto en el historial enviado (ahorra tokens).
    const keep = new Set(messages.value.filter((m) => m.attachments?.some((a) => a.text)).slice(-2));
    const history = messages.value
        .filter((m) => !m.error)
        .slice(-24)
        .map((m) => ({
            role: m.role,
            content: m.attachments?.length && !keep.has(m) ? `${m.content}\n[Adjuntó: ${m.attachments.map((a) => a.name).join(', ')}]` : m.content,
            attachments: keep.has(m) ? m.attachments?.filter((a) => a.text).map((a) => ({ name: a.name, text: a.text })) : undefined,
        }));

    try {
        const res = await sendJson<{ reply: string; actions: Action[]; pending?: Approval[] }>('POST', '/agent/chat', { messages: history });
        messages.value.push({ role: 'assistant', content: res.reply, actions: res.actions, approvals: res.pending?.length ? res.pending.map((p) => ({ ...p, state: 'waiting' as const })) : undefined });
        if (res.actions?.some((a) => a.ok && /^(create|add|move|update)_/.test(a.tool))) router.reload();
    } catch (e) {
        const msg = e instanceof HttpError ? (e.body?.message ?? (e.fieldErrors ? Object.values(e.fieldErrors)[0] : null) ?? `Error ${e.status}`) : 'No se pudo conectar con el Agent.';
        messages.value.push({ role: 'assistant', content: msg, error: true });
    } finally {
        busy.value = false;
        scrollDown();
        nextTick(() => field.value?.focus());
    }
};

/** Confirmar o cancelar una edición/eliminación que propuso el Agent (la acción se aplica en el servidor, con tus permisos). */
const resolve = async (a: Approval, ok: boolean) => {
    if (a.state !== 'waiting') return;
    a.state = ok ? 'working' : 'cancelled';
    try {
        if (!ok) {
            await sendJson('POST', `/agent/actions/${a.id}/cancel`);
            messages.value.push({ role: 'assistant', content: `Cancelaste «${a.title}»: no se aplicó ningún cambio.` });
            return;
        }
        const res = await sendJson<{ message: string; url: string | null }>('POST', `/agent/actions/${a.id}/confirm`);
        a.state = 'done';
        a.result = res.message;
        a.url = res.url;
        messages.value.push({ role: 'assistant', content: `Listo: ${res.message}` });
        router.reload();
    } catch (e) {
        a.state = 'error';
        a.result = e instanceof HttpError ? (e.body?.message ?? `Error ${e.status}`) : 'No se pudo conectar.';
        messages.value.push({ role: 'assistant', content: `No se aplicó «${a.title}»: ${a.result}`, error: true });
    } finally {
        scrollDown();
    }
};

const onKey = (e: KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        send();
    }
};
const useSuggestion = (s: string) => {
    if (s.endsWith(': ') || s.includes('…')) {
        input.value = s.replace('…', '');
        nextTick(() => {
            resize();
            field.value?.focus();
        });
    } else send(s);
};
const clear = () => {
    messages.value = [];
    pending.value = [];
};
const visit = (url: string) => {
    const u = new URL(url, window.location.origin);
    if (u.origin === window.location.origin) {
        router.visit(u.pathname + u.search);
        if (focus.value) focus.value = false; // al abrir un registro, el chat deja de tapar la página
    } else window.open(url, '_blank', 'noopener');
};
</script>

<template>
    <!-- Fondo del modo foco -->
    <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0" leave-active-class="transition duration-100" leave-to-class="opacity-0">
        <div v-if="open && focus" class="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm print:hidden" @click="focus = false" />
    </Transition>

    <div :class="cn('fixed z-50 flex print:hidden', focus && open ? 'inset-0 items-center justify-center p-4 pointer-events-none' : 'bottom-4 right-4 flex-col items-end gap-3')">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-2 scale-[0.98] opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="translate-y-2 opacity-0"
        >
            <section
                v-if="open"
                :class="cn('pointer-events-auto relative flex flex-col overflow-hidden rounded-2xl border bg-background shadow-2xl', focus ? 'h-[min(90dvh,920px)] w-[min(980px,calc(100vw-2rem))]' : 'h-[min(680px,calc(100dvh-6rem))] w-[min(430px,calc(100vw-2rem))]')"
                aria-label="Agent"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <header class="flex items-center gap-2 border-b bg-muted/40 px-4 py-3">
                    <Button variant="ghost" size="icon-sm" :title="focus ? 'Salir del modo foco (Esc)' : 'Modo foco: abrir al centro'" @click="focus = !focus">
                        <Minimize2 v-if="focus" /><Maximize2 v-else />
                    </Button>
                    <span class="flex size-8 items-center justify-center rounded-full bg-primary text-primary-foreground"><Sparkles class="size-4" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold leading-tight">Agent</div>
                        <div class="truncate text-xs text-muted-foreground">Pregunta, adjunta archivos o dicta por voz</div>
                    </div>
                    <Button v-if="messages.length" variant="ghost" size="icon-sm" title="Nueva conversación" @click="clear"><Trash2 /></Button>
                    <Button variant="ghost" size="icon-sm" title="Cerrar" @click="toggle"><X /></Button>
                </header>

                <div ref="scroller" class="flex-1 overflow-y-auto">
                    <div :class="cn('mx-auto w-full space-y-4 px-4 py-5', focus ? 'max-w-3xl' : '')">
                        <div v-if="status?.available === false" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                            Aún no hay un proveedor de IA configurado. Agrégalo en <strong>Inteligencia artificial → Proveedores</strong> para usar el Agent.
                        </div>

                        <div v-if="!messages.length" class="space-y-4">
                            <p class="text-sm text-muted-foreground">
                                Puedo buscar prospectos y clientes, crear propuestas desde un texto o un archivo (PDF, Word, Excel, TXT), registrar seguimientos, mover leads de etapa y decirte el estado de una propuesta.
                            </p>
                            <div :class="cn('grid gap-2', focus ? 'sm:grid-cols-2' : '')">
                                <button v-for="s in suggestions" :key="s" class="rounded-xl border px-3 py-2.5 text-left text-sm transition hover:bg-muted" @click="useSuggestion(s)">{{ s }}</button>
                            </div>
                        </div>

                        <div v-for="(m, i) in messages" :key="i" :class="cn('flex', m.role === 'user' ? 'justify-end' : 'justify-start')">
                            <div :class="cn('space-y-2 rounded-2xl px-4 py-2.5 text-sm leading-relaxed', focus ? 'max-w-[85%] text-[0.95rem]' : 'max-w-[90%]', m.role === 'user' ? 'bg-primary text-primary-foreground' : m.error ? 'border border-destructive/40 bg-destructive/5 text-destructive' : 'bg-muted')">
                                <template v-if="m.role === 'user'">
                                    <div v-if="m.attachments?.length" class="flex flex-wrap gap-1.5">
                                        <span v-for="a in m.attachments" :key="a.name" class="inline-flex max-w-full items-center gap-1 rounded-lg bg-white/20 px-2 py-1 text-xs"><FileText class="size-3.5 shrink-0" /><span class="truncate">{{ a.name }}</span></span>
                                    </div>
                                    <div class="whitespace-pre-wrap">{{ m.content }}</div>
                                </template>
                                <div v-else class="space-y-0.5 [&_a]:cursor-pointer" v-html="renderMarkdown(m.content)" @click.prevent="(e) => { const a = (e.target as HTMLElement).closest('a'); if (a) visit(a.getAttribute('href') || '') }" />
                                <ul v-if="m.actions?.length" class="space-y-1 border-t border-foreground/10 pt-2 text-xs">
                                    <li v-for="(a, j) in m.actions" :key="j" class="flex items-start gap-1.5">
                                        <Check v-if="a.ok" class="mt-0.5 size-3.5 shrink-0 text-brand-green" />
                                        <X v-else class="mt-0.5 size-3.5 shrink-0 text-destructive" />
                                        <span class="min-w-0 flex-1">{{ a.label }}</span>
                                        <button v-if="a.url" class="shrink-0 text-primary" title="Abrir" @click="visit(a.url)"><ExternalLink class="size-3.5" /></button>
                                    </li>
                                </ul>
                                <div v-for="a in m.approvals" :key="a.id" :class="cn('space-y-2 rounded-xl border p-3 text-xs', a.destructive ? 'border-destructive/40 bg-destructive/5' : 'border-primary/30 bg-primary/5')">
                                    <p class="flex items-start gap-1.5 text-sm font-semibold"><TriangleAlert v-if="a.destructive" class="mt-0.5 size-4 shrink-0 text-destructive" />{{ a.title }}</p>
                                    <ul class="space-y-0.5 text-muted-foreground"><li v-for="(l, k) in a.lines" :key="k">• {{ l }}</li></ul>
                                    <div v-if="a.state === 'waiting' || a.state === 'working'" class="flex gap-2 pt-1">
                                        <Button size="sm" :variant="a.destructive ? 'destructive' : 'default'" :disabled="a.state === 'working'" @click="resolve(a, true)"><Spinner v-if="a.state === 'working'" /> {{ a.destructive ? 'Sí, eliminar' : 'Confirmar cambio' }}</Button>
                                        <Button size="sm" variant="outline" :disabled="a.state === 'working'" @click="resolve(a, false)">Cancelar</Button>
                                    </div>
                                    <p v-else-if="a.state === 'done'" class="flex items-center gap-1.5 font-medium text-brand-green"><Check class="size-3.5" /> {{ a.result }} <button v-if="a.url" class="text-primary" title="Abrir" @click="visit(a.url)"><ExternalLink class="size-3.5" /></button></p>
                                    <p v-else-if="a.state === 'cancelled'" class="font-medium text-muted-foreground">Cancelado: no se aplicó.</p>
                                    <p v-else-if="a.state === 'error'" class="font-medium text-destructive">{{ a.result }}</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="busy" class="flex items-center gap-2 text-sm text-muted-foreground"><Spinner class="size-4" /> Trabajando en ello…</div>
                    </div>
                </div>

                <!-- Compositor -->
                <div class="border-t bg-background p-3">
                    <div :class="cn('mx-auto w-full', focus ? 'max-w-3xl' : '')">
                        <div v-if="pending.length" class="mb-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="p in pending"
                                :key="p.id"
                                :class="cn('inline-flex max-w-full items-center gap-1.5 rounded-lg border px-2 py-1 text-xs', p.status === 'error' ? 'border-destructive/40 bg-destructive/5 text-destructive' : 'bg-muted')"
                                :title="p.error ?? (p.truncated ? 'Archivo largo: se leyó la primera parte' : p.name)"
                            >
                                <Spinner v-if="p.status === 'loading'" class="size-3.5" /><FileText v-else class="size-3.5 shrink-0" />
                                <span class="truncate">{{ p.name }}</span>
                                <span v-if="p.status === 'ok'" class="text-muted-foreground">{{ fmtChars(p.chars) }}{{ p.truncated ? ' · parcial' : '' }}</span>
                                <span v-if="p.status === 'error'" class="max-w-[16rem] truncate">{{ p.error }}</span>
                                <button class="shrink-0 opacity-60 hover:opacity-100" title="Quitar" @click="removePending(p.id)"><X class="size-3.5" /></button>
                            </span>
                        </div>

                        <div class="rounded-2xl border bg-background focus-within:ring-2 focus-within:ring-primary/40">
                            <textarea
                                ref="field"
                                v-model="input"
                                :rows="1"
                                :class="cn('block w-full resize-none bg-transparent px-4 pt-3 text-sm outline-none', focus ? 'min-h-[3.25rem]' : 'min-h-11')"
                                :placeholder="recording ? 'Escuchando…' : 'Escribe o pega un texto, o adjunta un archivo…'"
                                :disabled="status?.available === false"
                                @input="resize"
                                @keydown="onKey"
                                @paste="onPaste"
                            />
                            <div class="flex items-center gap-1 px-2 pb-2 pt-1">
                                <input ref="fileInput" type="file" multiple class="hidden" :accept="accept" @change="onPick" />
                                <Button variant="ghost" size="icon-sm" title="Adjuntar PDF, Word, Excel o TXT" :disabled="pending.length >= 5" @click="fileInput?.click()"><Paperclip /></Button>
                                <Button v-if="canRecord" variant="ghost" size="icon-sm" :class="recording ? 'text-destructive' : ''" :title="recording ? 'Detener y enviar' : 'Dictar por voz'" :disabled="transcribing || busy" @click="toggleMic">
                                    <Square v-if="recording" class="fill-current" /><Mic v-else />
                                </Button>
                                <span v-if="recording" class="flex items-center gap-1.5 text-xs text-destructive"><span class="size-2 animate-pulse rounded-full bg-destructive" /> Grabando {{ mm }} · pulsa ■ para enviar</span>
                                <span v-else-if="transcribing" class="flex items-center gap-1.5 text-xs text-muted-foreground"><Spinner class="size-3.5" /> Transcribiendo…</span>
                                <span v-else class="hidden text-xs text-muted-foreground sm:inline">Enter envía · Shift+Enter salto de línea</span>
                                <Button class="ml-auto rounded-full" size="icon" :disabled="!canSend" title="Enviar" @click="send()"><ArrowUp /></Button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="dragging" class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-2xl border-2 border-dashed border-primary bg-primary/10 text-sm font-medium text-primary">
                    Suelta tus archivos aquí (PDF, Word, Excel, TXT)
                </div>
            </section>
        </Transition>

        <button
            v-if="!(open && focus)"
            class="flex items-center gap-2 rounded-full bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground shadow-lg transition hover:scale-105 hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50"
            :aria-expanded="open"
            aria-label="Abrir Agent"
            @click="toggle"
        >
            <Sparkles v-if="!open" class="size-4" /><X v-else class="size-4" />
            Agent
        </button>
    </div>
</template>
