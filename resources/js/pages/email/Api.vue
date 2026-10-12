<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, Plus, Power, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import ApiSnippets from '@/components/email/ApiSnippets.vue';
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
import { HttpError, sendJson } from '@/lib/http';
import { timeAgo } from '@/lib/format';
import { destroy, index, store, update } from '@/routes/api-keys';

type Key = { id: number; name: string; prefix: string; abilities: string[]; is_active: boolean; last_used_at: string | null; last_used_ip: string | null; created_at: string };
type Tpl = { id: number; name: string; slug: string; category: string; variables: { key: string; sample: string | null }[] | null };

const props = defineProps<{ keys: Key[]; abilities: Record<string, string>; endpoint: string; templates: Tpl[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Email · API e integraciones', href: index() }] } });

const open = ref(false);
const name = ref('');
const picked = ref<string[]>(['emails.send']);
const busy = ref(false);
const error = ref('');
const created = ref<string | null>(null);
const copied = ref(false);

const create = async () => {
    busy.value = true;
    error.value = '';
    try {
        const res = await sendJson<{ key: string }>('POST', store().url, { name: name.value, abilities: picked.value });
        created.value = res.key;
        name.value = '';
        router.reload({ only: ['keys'] });
    } catch (e) {
        error.value = e instanceof HttpError ? (Object.values(e.fieldErrors)[0] ?? 'No se pudo crear.') : 'No se pudo crear.';
    } finally {
        busy.value = false;
    }
};
const closeCreate = (v: boolean) => {
    open.value = v;
    if (!v) {
        created.value = null;
        error.value = '';
    }
};
const copyKey = async () => {
    await navigator.clipboard.writeText(created.value ?? '');
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};

const toggle = (k: Key) => router.put(update(k.id).url, { is_active: !k.is_active }, { preserveScroll: true });
const toDelete = ref<Key | null>(null);
const confirmDelete = () => {
    if (toDelete.value) router.delete(destroy(toDelete.value.id).url, { preserveScroll: true, onFinish: () => (toDelete.value = null) });
};

const tplSlug = ref(props.templates[0]?.slug ?? '');
const tpl = computed(() => props.templates.find((t) => t.slug === tplSlug.value));
const vars = computed(() => tpl.value?.variables?.map((v) => v.key) ?? []);
const sample = computed(() => Object.fromEntries((tpl.value?.variables ?? []).map((v) => [v.key, v.sample ?? ''])));
</script>

<template>
    <Head title="API e integraciones" />

    <div class="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="API e integraciones" description="Envía plantillas desde landings, formularios, Zapier/Make o tu propio backend.">
            <template #actions><Button @click="open = true"><Plus /> Nueva API key</Button></template>
        </PageHeader>

        <DataCard>
            <div class="border-b px-5 py-3 font-semibold">API keys</div>
            <p v-if="!keys.length" class="p-8 text-center text-sm text-muted-foreground"><KeyRound class="mx-auto mb-2 size-6" />Aún no tienes API keys.</p>
            <ul class="divide-y">
                <li v-for="k in keys" :key="k.id" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="!k.is_active && 'opacity-60'">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ k.name }} <code class="ml-1 text-xs text-muted-foreground">{{ k.prefix }}…</code></p>
                        <p class="text-xs text-muted-foreground">{{ k.last_used_at ? `Último uso ${timeAgo(k.last_used_at)} (${k.last_used_ip ?? 'IP desconocida'})` : 'Nunca usada' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-1"><Badge v-for="a in k.abilities" :key="a" variant="outline" class="font-mono text-[10px]">{{ a }}</Badge></div>
                    <Button variant="ghost" size="sm" @click="toggle(k)"><Power /> {{ k.is_active ? 'Desactivar' : 'Activar' }}</Button>
                    <Button variant="ghost" size="icon-sm" class="text-destructive hover:text-destructive" title="Eliminar" @click="toDelete = k"><Trash2 /></Button>
                </li>
            </ul>
        </DataCard>

        <DataCard class="p-6">
            <h2 class="mb-1 text-lg font-semibold">Referencia</h2>
            <p class="mb-4 text-sm text-muted-foreground">Base: <code>{{ endpoint }}</code> · Autenticación: <code>Authorization: Bearer TU_API_KEY</code> (o <code>X-Api-Key</code>).</p>

            <h3 class="mt-2 font-semibold"><code>POST /emails/send</code> <span class="text-xs font-normal text-muted-foreground">· requiere <code>emails.send</code></span></h3>
            <p class="mb-2 text-sm text-muted-foreground">Envía una plantilla a una o más personas. Responde <strong>202</strong> al encolarla; el envío real es asíncrono.</p>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-xs text-muted-foreground uppercase"><tr><th class="px-3 py-2">Parámetro</th><th class="px-3 py-2">Descripción</th></tr></thead>
                    <tbody class="divide-y">
                        <tr><td class="px-3 py-2"><code>template</code> *</td><td class="px-3 py-2">Identificador (slug) de la plantilla. También <code>template_id</code>.</td></tr>
                        <tr><td class="px-3 py-2"><code>to</code> *</td><td class="px-3 py-2">Correo (<code>"a@b.cl"</code>), objeto <code>{ "email", "name" }</code> o arreglo de hasta 50.</td></tr>
                        <tr><td class="px-3 py-2"><code>variables</code></td><td class="px-3 py-2">Objeto con los valores de las variables de la plantilla. Admite arreglos para <code v-pre>{{#each}}</code>.</td></tr>
                        <tr><td class="px-3 py-2"><em>cualquier otro parámetro</em></td><td class="px-3 py-2">Se toma como variable (útil con query string o formularios): <code>?template=x&to=a@b.cl&first_name=María</code>.</td></tr>
                        <tr><td class="px-3 py-2"><code>subject</code></td><td class="px-3 py-2">Reemplaza el asunto de la plantilla (admite variables).</td></tr>
                        <tr><td class="px-3 py-2"><code>lead_id</code></td><td class="px-3 py-2">Carga automáticamente los datos de ese prospecto como variables (<code>first_name</code>, <code>company</code>, <code>lead.*</code>…).</td></tr>
                        <tr><td class="px-3 py-2"><code>delay_minutes</code> / <code>send_at</code></td><td class="px-3 py-2">Programa el envío (minutos de espera, o fecha ISO 8601).</td></tr>
                        <tr><td class="px-3 py-2"><code>track_opens</code> / <code>track_clicks</code></td><td class="px-3 py-2">Medir aperturas y clics (por defecto <code>false</code> en transaccionales).</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">Respuesta: <code>{ "data": [{ "id": "uuid", "to": "…", "status": "queued", "warnings": { "missing_variables": [] } }] }</code> · Errores: <code>401</code> key inválida · <code>403</code> sin permiso · <code>404</code> plantilla inexistente · <code>422</code> validación · <code>429</code> límite (60/min por key).</p>

            <h3 class="mt-6 font-semibold"><code>GET /emails/{id}</code> <span class="text-xs font-normal text-muted-foreground">· requiere <code>emails.read</code></span></h3>
            <p class="text-sm text-muted-foreground">Estado de un envío (<code>queued → sent → delivered</code>, <code>bounced</code>, <code>complained</code>, <code>failed</code>), aperturas, clics y eventos.</p>

            <h3 class="mt-6 font-semibold"><code>POST /leads</code> + plantilla <span class="text-xs font-normal text-muted-foreground">· key de origen (Orígenes y API)</span></h3>
            <p class="text-sm text-muted-foreground">Al crear un prospecto puedes agregar <code>"email_template": "slug"</code> para enviarle una plantilla al instante con los mismos datos del prospecto (nombre, UTM, campos personalizados…). Para reglas sin código, usa <strong>Automatizaciones</strong>.</p>
        </DataCard>

        <DataCard class="p-6">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Ejemplos listos para copiar</h2>
                <NativeSelect v-model="tplSlug" class="w-64"><option v-for="t in templates" :key="t.id" :value="t.slug">{{ t.name }}</option></NativeSelect>
            </div>
            <ApiSnippets v-if="tpl" :slug="tpl.slug" :variables="vars" :sample="sample" :endpoint="endpoint" />
            <p v-else class="text-sm text-muted-foreground">Crea una plantilla para ver ejemplos con sus variables.</p>

            <div class="mt-5 rounded-xl bg-muted/60 p-4 text-sm">
                <p class="mb-1 font-semibold">Flujo típico: landing → correo de bienvenida</p>
                <ol class="list-decimal space-y-1 pl-5 text-muted-foreground">
                    <li>El formulario de la landing envía los datos a <strong>tu servidor</strong> (o a una automatización de Zapier/Make).</li>
                    <li>Tu servidor llama <code>POST /leads</code> con la key del origen «Landing» (el prospecto aparece en el Kanban) <strong>y</strong>, si quieres, <code>"email_template": "bienvenida"</code>.</li>
                    <li>Alternativa sin tocar código: crea una <strong>Automatización</strong> «Cuando ingresa un prospecto desde Landing → enviar plantilla Bienvenida».</li>
                </ol>
            </div>
        </DataCard>
    </div>

    <Dialog :open="open" @update:open="closeCreate">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ created ? 'API key creada' : 'Nueva API key' }}</DialogTitle>
                <DialogDescription>{{ created ? 'Cópiala ahora: por seguridad no se vuelve a mostrar.' : 'Elige qué podrá hacer esta key.' }}</DialogDescription>
            </DialogHeader>
            <div v-if="created" class="grid gap-3">
                <div class="flex items-center gap-2 rounded-xl bg-[#1e1e1e] p-3"><code class="min-w-0 flex-1 break-all text-xs text-[#e6e6e6]">{{ created }}</code><Button variant="ghost" size="icon-sm" class="text-white/70 hover:bg-white/10 hover:text-white" @click="copyKey"><Check v-if="copied" /><Copy v-else /></Button></div>
                <p class="flex items-start gap-2 text-xs text-muted-foreground"><TriangleAlert class="mt-0.5 size-4 shrink-0 text-[#FFA165]" />Guárdala en una variable de entorno de tu servidor.</p>
                <DialogFooter><Button @click="closeCreate(false)">Listo</Button></DialogFooter>
            </div>
            <form v-else class="grid gap-4" @submit.prevent="create">
                <FormField label="Nombre" for="kn" :error="error"><Input id="kn" v-model="name" placeholder="Ej: Landing Proyecto Norte" required /></FormField>
                <div class="grid gap-2">
                    <p class="text-sm font-medium">Permisos</p>
                    <label v-for="(label, key) in abilities" :key="key" class="flex items-start gap-2 text-sm"><input v-model="picked" type="checkbox" :value="key" class="mt-1" /><span><code class="text-xs">{{ key }}</code><br /><span class="text-muted-foreground">{{ label }}</span></span></label>
                </div>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="closeCreate(false)">Cancelar</Button><Button type="submit" :disabled="busy || !name || !picked.length"><Spinner v-if="busy" /> Crear</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toDelete" title="Eliminar API key" :description="`Se eliminará «${toDelete?.name}». Las integraciones que la usen dejarán de funcionar.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="confirmDelete" />
</template>
