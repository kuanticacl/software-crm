<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Cable, CheckCircle2, CircleAlert, KeyRound, Link2, Plus, Settings2, ShieldAlert, ShieldCheck, Trash2, Unplug, Zap } from '@lucide/vue';
import { reactive, ref } from 'vue';
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
import { timeAgo } from '@/lib/format';
import { HttpError, sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { destroy, index, store, test as testRoute, tools as toolsRoute, update } from '@/routes/ai/mcp';
import { disconnect as oauthDisconnect, start as oauthStart } from '@/routes/ai/mcp/oauth';

type Tool = { name: string; description: string; read_only: boolean | null; destructive: boolean | null; enabled: boolean; confirm: boolean };
type Server = {
    id: number; name: string; url: string; auth_type: 'none' | 'api_key' | 'oauth'; api_key_header: string | null; api_key_prefix: string | null; has_key: boolean; key_masked: string | null;
    oauth_client_id: string | null; has_client_secret: boolean; oauth_scopes: string | null; oauth_connected: boolean; oauth_expires_at: string | null;
    is_enabled: boolean; require_confirmation: boolean; tools: Tool[]; connected: boolean; last_tested_at: string | null; last_test_ok: boolean | null; last_test_message: string | null;
};
type Call = { id: number; server: string | null; user: string | null; tool: string; status: string; summary: string | null; arguments: string | null; ms: number; at: string };

const props = defineProps<{ servers: Server[]; redirectUri: string; calls: Call[]; allowPrivate: boolean }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Herramientas MCP', href: index() }] } });

// ---------------------------------------------------------------- alta / edición
const editing = ref<Server | 'new' | null>(null);
const form = useForm({ name: '', url: '', auth_type: 'none', api_key_header: 'Authorization', api_key_prefix: 'Bearer ', api_key: '', oauth_client_id: '', oauth_client_secret: '', oauth_scopes: '' });
const dialogOpen = ref(false);

const openForm = (s: Server | 'new') => {
    editing.value = s;
    const v = s === 'new' ? null : s;
    form.defaults({
        name: v?.name ?? '', url: v?.url ?? '', auth_type: v?.auth_type ?? 'none', api_key_header: v?.api_key_header ?? 'Authorization', api_key_prefix: v?.api_key_prefix ?? 'Bearer ',
        api_key: '', oauth_client_id: v?.oauth_client_id ?? '', oauth_client_secret: '', oauth_scopes: v?.oauth_scopes ?? '',
    }).reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const save = () => {
    const target = editing.value;
    if (!target) return;
    const opts = { preserveScroll: true, onSuccess: () => (dialogOpen.value = false) };
    if (target === 'new') form.submit(store(), opts);
    else form.submit(update(target.id), opts);
};

// ---------------------------------------------------------------- probar / conectar
const busy = reactive<Record<string, boolean>>({});
const results = reactive<Record<number, { ok: boolean; message: string }>>({});
const errMsg = (e: unknown, fallback: string) => (e instanceof HttpError ? ((e.body as { message?: string } | null)?.message ?? fallback) : fallback);

const runTest = async (s: Server) => {
    busy[`t${s.id}`] = true;
    try {
        results[s.id] = await sendJson('POST', testRoute(s.id).url);
    } catch (e) {
        results[s.id] = { ok: false, message: errMsg(e, 'No se pudo probar.') };
    } finally {
        busy[`t${s.id}`] = false;
        router.reload({ only: ['servers'] });
    }
};
const connect = async (s: Server) => {
    busy[`o${s.id}`] = true;
    try {
        const r = await sendJson<{ url: string }>('POST', oauthStart(s.id).url);
        window.location.href = r.url;
    } catch (e) {
        results[s.id] = { ok: false, message: errMsg(e, 'No se pudo iniciar la conexión.') };
        busy[`o${s.id}`] = false;
    }
};
const disconnect = (s: Server) => router.post(oauthDisconnect(s.id).url, {}, { preserveScroll: true });
const toggle = (s: Server, key: 'is_enabled' | 'require_confirmation', v: boolean) =>
    router.put(update(s.id).url, { name: s.name, url: s.url, auth_type: s.auth_type, api_key_header: s.api_key_header, api_key_prefix: s.api_key_prefix, oauth_client_id: s.oauth_client_id, oauth_scopes: s.oauth_scopes, [key]: v }, { preserveScroll: true });

// ---------------------------------------------------------------- herramientas
const toolsFor = ref<Server | null>(null);
const sel = reactive<{ enabled: string[]; confirm: string[] }>({ enabled: [], confirm: [] });
const openTools = (s: Server) => {
    toolsFor.value = s;
    sel.enabled = s.tools.filter((t) => t.enabled).map((t) => t.name);
    sel.confirm = s.tools.filter((t) => t.confirm).map((t) => t.name);
};
const flip = (list: string[], name: string, on: boolean) => {
    const i = list.indexOf(name);
    if (on && i < 0) list.push(name);
    if (!on && i >= 0) list.splice(i, 1);
};
const saveTools = () => toolsFor.value && router.put(toolsRoute(toolsFor.value.id).url, { enabled: sel.enabled, confirm: sel.confirm }, { preserveScroll: true, onSuccess: () => (toolsFor.value = null) });

const toDelete = ref<Server | null>(null);
const confirmDelete = () => toDelete.value && router.delete(destroy(toDelete.value.id).url, { preserveScroll: true, onFinish: () => (toDelete.value = null) });

const status = (s: Server) => {
    if (!s.is_enabled) return { label: 'Deshabilitado', cls: 'bg-muted text-muted-foreground' };
    if (!s.connected) return { label: s.auth_type === 'oauth' ? 'Sin conectar' : 'Falta la key', cls: 'bg-[#FFA165]/20 text-[#9A4B00]' };
    if (s.last_test_ok === false) return { label: 'Con error', cls: 'bg-destructive/10 text-destructive' };
    if (s.last_test_ok) return { label: 'Conectado', cls: 'bg-brand-green/10 text-brand-green' };
    return { label: 'Sin probar', cls: 'bg-[#FFA165]/20 text-[#9A4B00]' };
};
const authLabel = { none: 'Sin autenticación', api_key: 'API key', oauth: 'OAuth 2.1' } as const;
const callCls = (st: string) => (st === 'ok' ? 'bg-brand-green/10 text-brand-green' : st === 'denied' ? 'bg-[#FFA165]/20 text-[#9A4B00]' : 'bg-destructive/10 text-destructive');
</script>

<template>
    <Head title="Herramientas MCP" />

    <div class="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Herramientas MCP" description="Conecta servidores MCP externos para darle a Lunita más herramientas (buscadores, análisis, otros sistemas), con API key u OAuth.">
            <template #actions><Button @click="openForm('new')"><Plus /> Agregar servidor</Button></template>
        </PageHeader>

        <div class="grid gap-3 rounded-2xl border bg-card p-4 text-sm text-muted-foreground md:grid-cols-3">
            <p class="flex items-start gap-3"><ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" /><span><strong class="text-foreground">Credenciales cifradas.</strong> Keys y tokens OAuth se guardan cifrados y nunca vuelven al navegador. OAuth usa PKCE y se renueva solo.</span></p>
            <p class="flex items-start gap-3"><ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" /><span><strong class="text-foreground">Tú decides qué usa Lunita.</strong> Cada herramienta se habilita una a una; las que modifican datos piden tu confirmación antes de ejecutarse.</span></p>
            <p class="flex items-start gap-3"><ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" /><span><strong class="text-foreground">Aislado y auditado.</strong> Solo https público (bloquea redes internas), sin redirecciones, respuestas acotadas y registro de cada llamada.</span></p>
        </div>
        <p v-if="allowPrivate" class="flex items-center gap-2 rounded-xl bg-destructive/10 p-3 text-xs text-destructive"><ShieldAlert class="size-4" /> MCP_ALLOW_PRIVATE está activo: se permiten servidores en redes privadas. Desactívalo en producción.</p>

        <DataCard v-if="!servers.length" class="p-10 text-center">
            <Cable class="mx-auto mb-3 size-8 text-muted-foreground" />
            <h2 class="font-semibold">Aún no hay servidores MCP</h2>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">Agrega la URL de un servidor MCP remoto (Streamable HTTP). Podrás elegir exactamente qué herramientas puede usar Lunita.</p>
            <Button class="mt-4" @click="openForm('new')"><Plus /> Agregar servidor</Button>
        </DataCard>

        <div class="grid gap-4 lg:grid-cols-2">
            <article v-for="s in servers" :key="s.id" class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="truncate font-semibold">{{ s.name }}</h3>
                        <p class="truncate font-mono text-xs text-muted-foreground" :title="s.url">{{ s.url }}</p>
                    </div>
                    <Badge :class="['shrink-0 border-transparent', status(s).cls]">{{ status(s).label }}</Badge>
                </div>

                <dl class="grid gap-1.5 text-xs">
                    <div class="flex items-center justify-between gap-3"><dt class="text-muted-foreground">Autenticación</dt><dd>{{ authLabel[s.auth_type] }}<template v-if="s.auth_type === 'api_key' && s.key_masked"> · <span class="font-mono">{{ s.key_masked }}</span></template></dd></div>
                    <div class="flex items-center justify-between gap-3"><dt class="text-muted-foreground">Herramientas habilitadas</dt><dd>{{ s.tools.filter((t) => t.enabled).length }} de {{ s.tools.length }}</dd></div>
                    <div v-if="s.last_tested_at" class="flex items-center justify-between gap-3"><dt class="text-muted-foreground">Última prueba</dt><dd>{{ timeAgo(s.last_tested_at) }}</dd></div>
                </dl>

                <p v-if="results[s.id]" :class="cn('flex items-start gap-2 rounded-xl p-3 text-xs', results[s.id].ok ? 'bg-brand-green/10 text-brand-green' : 'bg-destructive/10 text-destructive')">
                    <CheckCircle2 v-if="results[s.id].ok" class="mt-0.5 size-4 shrink-0" /><CircleAlert v-else class="mt-0.5 size-4 shrink-0" />{{ results[s.id].message }}
                </p>
                <p v-else-if="s.last_test_ok === false && s.last_test_message" class="flex items-start gap-2 rounded-xl bg-destructive/10 p-3 text-xs text-destructive"><CircleAlert class="mt-0.5 size-4 shrink-0" />{{ s.last_test_message }}</p>

                <div class="grid gap-2 rounded-xl border p-3 text-sm">
                    <label class="flex items-center justify-between gap-3"><span>Disponible para Lunita</span><Switch :model-value="s.is_enabled" @update:model-value="(v: boolean) => toggle(s, 'is_enabled', v)" /></label>
                    <label class="flex items-center justify-between gap-3"><span>Pedir confirmación en herramientas que modifican<span v-if="!s.require_confirmation" class="ml-1 text-xs text-destructive">(desactivado)</span></span><Switch :model-value="s.require_confirmation" @update:model-value="(v: boolean) => toggle(s, 'require_confirmation', v)" /></label>
                </div>

                <div class="mt-auto flex flex-wrap items-center gap-2 border-t pt-3">
                    <Button v-if="s.auth_type === 'oauth' && !s.oauth_connected" size="sm" :disabled="busy[`o${s.id}`]" @click="connect(s)"><Spinner v-if="busy[`o${s.id}`]" /><Link2 v-else /> Conectar cuenta</Button>
                    <Button v-if="s.connected" size="sm" variant="outline" :disabled="busy[`t${s.id}`]" @click="runTest(s)"><Spinner v-if="busy[`t${s.id}`]" /><Zap v-else /> Probar y descubrir</Button>
                    <Button v-if="s.tools.length" size="sm" variant="outline" @click="openTools(s)"><Settings2 /> Herramientas</Button>
                    <Button v-if="s.auth_type === 'oauth' && s.oauth_connected" size="sm" variant="ghost" @click="disconnect(s)"><Unplug /> Desconectar</Button>
                    <Button size="sm" variant="ghost" @click="openForm(s)"><KeyRound /> Editar</Button>
                    <Button size="icon-sm" variant="ghost" class="ml-auto text-destructive hover:text-destructive" title="Eliminar servidor y credenciales" @click="toDelete = s"><Trash2 /></Button>
                </div>
            </article>
        </div>

        <DataCard v-if="calls.length" class="overflow-hidden">
            <div class="border-b p-4"><h2 class="font-semibold">Registro de llamadas</h2><p class="text-xs text-muted-foreground">Las últimas 40 ejecuciones de herramientas externas (los parámetros sensibles se ocultan).</p></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-muted/40 text-muted-foreground"><tr><th class="px-4 py-2 font-medium">Cuándo</th><th class="px-4 py-2 font-medium">Usuario</th><th class="px-4 py-2 font-medium">Servidor · herramienta</th><th class="px-4 py-2 font-medium">Estado</th><th class="px-4 py-2 font-medium">Detalle</th></tr></thead>
                    <tbody>
                        <tr v-for="c in calls" :key="c.id" class="border-t align-top">
                            <td class="whitespace-nowrap px-4 py-2">{{ timeAgo(c.at) }}<br /><span class="text-muted-foreground">{{ c.ms }} ms</span></td>
                            <td class="px-4 py-2">{{ c.user ?? '—' }}</td>
                            <td class="px-4 py-2"><span class="font-medium">{{ c.server }}</span><br /><span class="font-mono">{{ c.tool }}</span></td>
                            <td class="px-4 py-2"><Badge :class="['border-transparent', callCls(c.status)]">{{ c.status }}</Badge></td>
                            <td class="max-w-sm px-4 py-2"><p class="truncate font-mono text-muted-foreground" :title="c.arguments ?? ''">{{ c.arguments }}</p><p class="line-clamp-2" :title="c.summary ?? ''">{{ c.summary }}</p></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </DataCard>
    </div>

    <!-- Alta / edición -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>{{ editing === 'new' ? 'Agregar servidor MCP' : 'Editar servidor MCP' }}</DialogTitle>
                <DialogDescription>Servidores remotos con transporte Streamable HTTP. Las credenciales se guardan cifradas.</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <FormField label="Nombre" for="m-name" :error="form.errors.name" required><Input id="m-name" v-model="form.name" placeholder="Ej.: Búsqueda de competencia" /></FormField>
                <FormField label="URL del servidor MCP" for="m-url" :error="form.errors.url" hint="Debe ser https:// y apuntar al endpoint MCP (p. ej. https://mcp.ejemplo.com/mcp)." required><Input id="m-url" v-model="form.url" type="url" placeholder="https://mcp.ejemplo.com/mcp" /></FormField>
                <FormField label="Autenticación" for="m-auth">
                    <NativeSelect id="m-auth" v-model="form.auth_type">
                        <option value="none">Sin autenticación</option>
                        <option value="api_key">API key (encabezado HTTP)</option>
                        <option value="oauth">OAuth 2.1 (conectar una cuenta)</option>
                    </NativeSelect>
                </FormField>

                <template v-if="form.auth_type === 'api_key'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Encabezado" for="m-hdr" :error="form.errors.api_key_header" hint="Casi siempre «Authorization» o «X-API-Key»."><Input id="m-hdr" v-model="form.api_key_header" /></FormField>
                        <FormField label="Prefijo del valor" for="m-pfx" :error="form.errors.api_key_prefix" hint="«Bearer » (con espacio) o vacío."><Input id="m-pfx" v-model="form.api_key_prefix" /></FormField>
                    </div>
                    <FormField label="API key" for="m-key" :error="form.errors.api_key" :hint="editing !== 'new' && editing?.has_key ? `Guardada (${editing.key_masked}). Déjala vacía para conservarla.` : 'Se guarda cifrada y no se vuelve a mostrar.'">
                        <Input id="m-key" v-model="form.api_key" type="password" autocomplete="off" placeholder="Pega la API key" />
                    </FormField>
                </template>

                <template v-if="form.auth_type === 'oauth'">
                    <p class="rounded-xl bg-muted/50 p-3 text-xs text-muted-foreground">Al guardar, pulsa «Conectar cuenta». Si el servidor admite registro automático no necesitas nada más; si no, ingresa el Client ID del proveedor y registra esta URL de retorno: <code class="break-all text-foreground">{{ redirectUri }}</code></p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField label="Client ID (opcional)" for="m-cid" :error="form.errors.oauth_client_id"><Input id="m-cid" v-model="form.oauth_client_id" autocomplete="off" /></FormField>
                        <FormField label="Client secret (opcional)" for="m-cs" :error="form.errors.oauth_client_secret" :hint="editing !== 'new' && editing?.has_client_secret ? 'Guardado. Vacío = conservar.' : undefined"><Input id="m-cs" v-model="form.oauth_client_secret" type="password" autocomplete="off" /></FormField>
                    </div>
                    <FormField label="Permisos (scopes, opcional)" for="m-sc" :error="form.errors.oauth_scopes" hint="Separados por espacio. Vacío = los que sugiera el servidor."><Input id="m-sc" v-model="form.oauth_scopes" /></FormField>
                </template>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="dialogOpen = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Herramientas -->
    <Dialog :open="!!toolsFor" @update:open="(v: boolean) => !v && (toolsFor = null)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Herramientas de {{ toolsFor?.name }}</DialogTitle>
                <DialogDescription>Habilita solo lo que Lunita necesita. «Confirmar» hace que te pida aprobación con un botón antes de ejecutarla.</DialogDescription>
            </DialogHeader>
            <ul v-if="toolsFor" class="grid gap-2">
                <li v-for="t in toolsFor.tools" :key="t.name" class="grid gap-2 rounded-xl border p-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs font-semibold">{{ t.name }}
                                <Badge v-if="t.read_only" variant="secondary" class="ml-1">solo lectura</Badge>
                                <Badge v-else-if="t.destructive" class="ml-1 border-transparent bg-destructive/10 text-destructive">destructiva</Badge>
                                <Badge v-else-if="t.read_only === false" variant="secondary" class="ml-1">modifica</Badge>
                            </p>
                            <p class="mt-1 line-clamp-3 text-xs text-muted-foreground">{{ t.description || 'Sin descripción' }}</p>
                        </div>
                        <label class="flex shrink-0 items-center gap-2 text-xs">Usar <Switch :model-value="sel.enabled.includes(t.name)" @update:model-value="(v: boolean) => flip(sel.enabled, t.name, v)" /></label>
                    </div>
                    <label v-if="sel.enabled.includes(t.name)" class="flex items-center justify-between gap-3 border-t pt-2 text-xs text-muted-foreground">Pedir confirmación antes de ejecutar<Switch :model-value="sel.confirm.includes(t.name)" @update:model-value="(v: boolean) => flip(sel.confirm, t.name, v)" /></label>
                </li>
            </ul>
            <DialogFooter><Button variant="outline" @click="toolsFor = null">Cancelar</Button><Button @click="saveTools">Guardar</Button></DialogFooter>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toDelete" title="¿Eliminar el servidor MCP?" :description="`Se borra «${toDelete?.name}» con sus credenciales y su registro de llamadas. Lunita dejará de usar sus herramientas.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="confirmDelete" />
</template>
