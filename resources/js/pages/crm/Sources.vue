<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Eye,
    EyeOff,
    KeyRound,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ColorPicker from '@/components/ColorPicker.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeleteWithRelatedDialog from '@/components/DeleteWithRelatedDialog.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SourceIcon from '@/components/SourceIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { sourceIconNames } from '@/lib/sourceIcons';
import { cn } from '@/lib/utils';
import { defaults as defaultsRoute, destroy, index, regenerateKey, store, update } from '@/routes/sources';

type Source = {
    id: number;
    name: string;
    slug: string;
    color: string;
    icon: string;
    is_active: boolean;
    score_weight: number;
    is_system: boolean;
    leads_count: number;
    proposals_count: number;
    api_key: string;
    owner_ids: number[];
};
type Person = { id: number; name: string; email: string };

defineOptions({
    layout: { breadcrumbs: [{ title: 'Orígenes de clientes', href: index() }] },
});

const props = defineProps<{ sources: Source[]; endpoint: string; users: Person[]; defaults: { assignee_id: number | null; notify_emails: string } }>();

const dialogOpen = ref(false);
const editing = ref<Source | null>(null);
const form = useForm({ name: '', color: '#1AA0E4', icon: 'globe', is_active: true, score_weight: 0, owner_ids: [] as number[] });

// Valores por defecto: responsable de todo cliente sin responsable de origen y correos que reciben cada alerta.
const defaultsForm = useForm({ assignee_id: (props.defaults.assignee_id ?? '') as number | '', notify_emails: props.defaults.notify_emails });
const saveDefaults = () =>
    defaultsForm
        .transform((d) => ({ ...d, assignee_id: d.assignee_id || null }))
        .put(defaultsRoute().url, { preserveScroll: true });
const ownerNames = (s: Source) => props.users.filter((u) => s.owner_ids.includes(u.id)).map((u) => u.name);

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const openEdit = (s: Source) => {
    editing.value = s;
    form.defaults({ name: s.name, color: s.color, icon: s.icon, is_active: s.is_active, score_weight: s.score_weight, owner_ids: [...s.owner_ids] }).reset();
    form.clearErrors();
    dialogOpen.value = true;
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (dialogOpen.value = false) };
    editing.value
        ? form.submit(update(editing.value.id), opts)
        : form.submit(store(), opts);
};

const revealed = ref<Set<number>>(new Set());
const toggleReveal = (id: number) => {
    revealed.value.has(id) ? revealed.value.delete(id) : revealed.value.add(id);
    revealed.value = new Set(revealed.value);
};
const mask = (k: string) => k.slice(0, 7) + '•'.repeat(24);

const copied = ref<string | null>(null);
const copy = async (text: string, id: string) => {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = id;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        toast.error('No se pudo copiar al portapapeles.');
    }
};

const toDelete = ref<Source | null>(null);
const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;
const deleteSummary = computed(() => {
    const s = toDelete.value;
    if (!s?.leads_count) return '';
    return [plural(s.leads_count, 'lead', 'leads'), s.proposals_count ? plural(s.proposals_count, 'propuesta', 'propuestas') : ''].filter(Boolean).join(' y ');
});
const deleteTargets = computed(() => props.sources.filter((x) => x.id !== toDelete.value?.id && x.is_active).map((x) => ({ id: x.id, name: x.name })));
const toRegen = ref<Source | null>(null);
const act = (kind: 'delete' | 'regen', extra: { transfer_to?: number | null } = {}) => {
    if (kind === 'delete' && toDelete.value) {
        router.delete(destroy(toDelete.value.id).url, {
            data: { confirm_related: 1, ...(extra.transfer_to ? { transfer_to: extra.transfer_to } : {}) },
            preserveScroll: true,
            onFinish: () => (toDelete.value = null),
        });
    }
    if (kind === 'regen' && toRegen.value) {
        router.post(regenerateKey(toRegen.value.id).url, {}, {
            preserveScroll: true,
            onFinish: () => (toRegen.value = null),
        });
    }
};

const sample = (s?: Source) => `curl -X POST ${props.endpoint} \\
  -H "X-Api-Key: ${s?.api_key ?? 'TU_API_KEY'}" \\
  -H "Content-Type: application/json" \\
  -d '{
    "first_name": "María",
    "last_name": "González",
    "email": "maria@ejemplo.cl",
    "phone": "+56912345678",
    "company": "Taller Ejemplo",
    "job_title": "Gerente comercial",
    "message": "Quiero cotizar una campaña",
    "utm_source": "google",
    "utm_medium": "cpc",
    "utm_campaign": "proyecto-otono",
    "landing_url": "https://tusitio.cl/contacto",
    "referrer": "https://www.google.com/",
    "custom": { "proyecto_interes": "Torre Norte" }
  }'`;
</script>

<template>
    <Head title="Orígenes de clientes" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Orígenes de clientes"
            description="Cada origen tiene su propia API key: el origen del cliente se reconoce por la key con la que llega."
        >
            <template #actions>
                <Button @click="openCreate"><Plus /> Nuevo origen</Button>
            </template>
        </PageHeader>

        <form class="grid gap-4 rounded-2xl border bg-card p-4 shadow-sm shadow-black/[0.03] md:grid-cols-2" @submit.prevent="saveDefaults">
            <div class="md:col-span-2">
                <h3 class="font-semibold">Asignación y alertas por defecto</h3>
                <p class="text-xs text-muted-foreground">
                    Ningún cliente queda sin asignar: va al responsable de su origen (si son varios, se reparten parejo) o, si el origen no tiene, al responsable por defecto. Cada cliente nuevo avisa por correo al responsable asignado, a los responsables del origen y a los correos de abajo (automatización «Alerta de cliente nuevo al equipo»).
                </p>
            </div>
            <FormField label="Responsable por defecto" for="d-assignee" :error="defaultsForm.errors.assignee_id">
                <NativeSelect id="d-assignee" v-model="defaultsForm.assignee_id">
                    <option value="">Sin responsable por defecto</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} · {{ u.email }}</option>
                </NativeSelect>
            </FormField>
            <FormField label="Correos que reciben todas las alertas" for="d-emails" :error="defaultsForm.errors.notify_emails" hint="Separados por coma o salto de línea. Ej: admin@mecaniq.cl">
                <Textarea id="d-emails" v-model="defaultsForm.notify_emails" rows="2" placeholder="admin@mecaniq.cl" />
            </FormField>
            <div class="md:col-span-2">
                <Button type="submit" :disabled="defaultsForm.processing"><Spinner v-if="defaultsForm.processing" /> Guardar valores por defecto</Button>
            </div>
        </form>

        <div class="grid gap-4 xl:grid-cols-3">
            <div class="flex flex-col gap-3 xl:col-span-2">
                <div
                    v-for="s in sources"
                    :key="s.id"
                    class="flex flex-col gap-4 rounded-2xl border bg-card p-4 shadow-sm shadow-black/[0.03]"
                    :class="!s.is_active && 'opacity-60'"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white [&_svg]:size-5"
                            :style="{ backgroundColor: s.color }"
                        >
                            <SourceIcon :name="s.icon" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold">{{ s.name }}</h3>
                                <Badge v-if="s.is_system" variant="outline">Sistema</Badge>
                                <Badge v-if="!s.is_active" variant="secondary">Inactivo</Badge>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ s.leads_count }}
                                {{ s.leads_count === 1 ? 'lead' : 'leads' }} · identificador
                                <code>{{ s.slug }}</code>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Responsable{{ ownerNames(s).length > 1 ? 's' : '' }}:
                                <span class="text-foreground">{{ ownerNames(s).length ? ownerNames(s).join(', ') : 'el responsable por defecto' }}</span>
                            </p>
                        </div>
                        <Button variant="ghost" size="icon-sm" title="Editar" @click="openEdit(s)"><Pencil /></Button>
                        <Button
                            v-if="!s.is_system"
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive hover:text-destructive"
                            title="Eliminar"
                            @click="toDelete = s"
                        >
                            <Trash2 />
                        </Button>
                    </div>

                    <div class="flex items-center gap-2 rounded-xl bg-muted/60 px-3 py-2">
                        <KeyRound class="size-4 shrink-0 text-muted-foreground" />
                        <code class="min-w-0 flex-1 truncate text-xs">
                            {{ revealed.has(s.id) ? s.api_key : mask(s.api_key) }}
                        </code>
                        <Button variant="ghost" size="icon-sm" :title="revealed.has(s.id) ? 'Ocultar' : 'Mostrar'" @click="toggleReveal(s.id)">
                            <EyeOff v-if="revealed.has(s.id)" /><Eye v-else />
                        </Button>
                        <Button variant="ghost" size="icon-sm" title="Copiar API key" @click="copy(s.api_key, 'k' + s.id)">
                            <Check v-if="copied === 'k' + s.id" class="text-brand-green" /><Copy v-else />
                        </Button>
                        <Button variant="ghost" size="icon-sm" title="Generar nueva key" @click="toRegen = s"><RefreshCw /></Button>
                    </div>
                </div>
            </div>

            <div class="flex h-fit flex-col gap-3 rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                <h3 class="font-semibold">Enviar clientes por API</h3>
                <p class="text-sm text-muted-foreground">
                    Envía un <code>POST</code> con la API key del origen en el header
                    <code>X-Api-Key</code>. La IP, el navegador y la ubicación (si el servidor
                    las recibe) se capturan automáticamente.
                </p>
                <div class="rounded-xl bg-[#1e1e1e] p-3">
                    <pre class="overflow-x-auto text-[11px] leading-relaxed text-[#e6e6e6]">{{ sample(sources[0]) }}</pre>
                </div>
                <Button variant="outline" size="sm" class="w-fit" @click="copy(sample(sources[0]), 'sample')">
                    <Check v-if="copied === 'sample'" /><Copy v-else /> Copiar ejemplo
                </Button>
                <p class="text-xs text-muted-foreground">
                    Campos: <code>first_name</code> (requerido), <code>last_name</code>,
                    <code>email</code>, <code>phone</code>, <code>job_title</code>, <code>company</code>,
                    <code>message</code>, <code>utm_*</code>, <code>landing_url</code>,
                    <code>referrer</code>, <code>ip</code>, <code>user_agent</code>,
                    <code>country</code>, <code>region</code>, <code>city</code>,
                    <code>latitude</code>, <code>longitude</code>, y <code>custom</code> con los
                    campos personalizados por su clave. Cualquier otro dato se guarda en los
                    metadatos del cliente y queda disponible como variable en los emails.
                    Con <code>email_template</code> (slug de una plantilla) se le envía además
                    un correo al cliente; para reglas sin código usa Email → Automatizaciones.
                </p>
            </div>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ editing ? 'Editar origen' : 'Nuevo origen' }}</DialogTitle>
                <DialogDescription>
                    {{ editing ? 'Cambia nombre, color o ícono.' : 'Se generará una API key única para este origen.' }}
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <FormField label="Nombre" for="s-name" :error="form.errors.name" required>
                    <Input id="s-name" v-model="form.name" placeholder="Ej: Facebook Lead Ads" />
                </FormField>
                <FormField label="Color" :error="form.errors.color">
                    <ColorPicker v-model="form.color" />
                </FormField>
                <FormField label="Ícono" :error="form.errors.icon">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="n in sourceIconNames"
                            :key="n"
                            type="button"
                            :class="cn('flex size-9 items-center justify-center rounded-lg border transition [&_svg]:size-4', form.icon === n ? 'border-primary bg-accent text-accent-foreground' : 'hover:bg-muted')"
                            @click="form.icon = n"
                        >
                            <SourceIcon :name="n" />
                        </button>
                    </div>
                </FormField>
                <FormField label="Calidad del origen para el puntaje (0–10)" :error="form.errors.score_weight" hint="Suma al puntaje de cada cliente de este origen. Ej: referidos y demos = 8–10, formularios fríos = 2–4.">
                    <Input v-model.number="form.score_weight" type="number" min="0" max="10" step="1" />
                </FormField>
                <FormField label="Responsables del origen" :error="form.errors.owner_ids" hint="Reciben la alerta de cada cliente nuevo de este origen y se les asignan sus clientes (si eliges varios, se reparten parejo). Sin responsables, se usa el responsable por defecto.">
                    <div class="grid max-h-44 gap-1 overflow-y-auto rounded-xl border p-2">
                        <label v-for="u in users" :key="u.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 text-sm hover:bg-muted">
                            <input v-model="form.owner_ids" type="checkbox" :value="u.id" class="size-4 accent-primary" />
                            <span class="min-w-0 flex-1 truncate">{{ u.name }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{ u.email }}</span>
                        </label>
                    </div>
                </FormField>
                <label v-if="!editing?.is_system" class="flex items-center gap-3 text-sm">
                    <Switch :model-value="form.is_active" @update:model-value="(v: boolean) => (form.is_active = v)" />
                    Activo (acepta clientes)
                </label>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="dialogOpen = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" /> Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        :open="!!toDelete && !toDelete.leads_count"
        title="Eliminar origen"
        :description="`Se eliminará «${toDelete?.name}» y su API key dejará de funcionar.`"
        confirm-label="Eliminar"
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="act('delete')"
    />
    <DeleteWithRelatedDialog
        :open="!!toDelete && toDelete.leads_count > 0"
        :title="`Eliminar origen «${toDelete?.name}»`"
        :summary="deleteSummary"
        :targets="deleteTargets"
        transfer-label="Transferirlos a otro origen"
        @update:open="(v: boolean) => !v && (toDelete = null)"
        @confirm="(o) => act('delete', o)"
    />
    <ConfirmDialog
        :open="!!toRegen"
        title="Generar nueva API key"
        :description="`La key actual de «${toRegen?.name}» dejará de funcionar de inmediato. Tendrás que actualizarla donde se use.`"
        confirm-label="Generar nueva key"
        :destructive="false"
        @update:open="(v: boolean) => !v && (toRegen = null)"
        @confirm="act('regen')"
    />
</template>
