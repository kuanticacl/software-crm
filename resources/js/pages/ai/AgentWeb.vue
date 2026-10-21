<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Bot, CircleAlert, RotateCcw, ShieldCheck } from '@lucide/vue';
import { toast } from 'vue-sonner';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    settings: { enabled: boolean; name: string; company: string; prompt: string; prompt_is_default: boolean; knowledge: string; greeting: string };
    defaultPrompt: string;
    defaultGreeting: string;
    safety: string;
    contact: string;
    aiAvailable: boolean;
    limits: { messages: number; chars: number };
    usage: { runs: number; errors: number };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Agente web', href: '/ai/agentweb' }] } });

const form = useForm({ enabled: props.settings.enabled, name: props.settings.name, prompt: props.settings.prompt, knowledge: props.settings.knowledge, greeting: props.settings.greeting });
const save = () => form.put('/ai/agentweb', { preserveScroll: true, onSuccess: () => toast.success('Ajustes del asistente guardados.') });
</script>

<template>
    <Head title="Agente web" />
    <div class="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Agente web" :description="`Asistente flotante del sitio de ${settings.company}: conversa con los visitantes, responde sobre la empresa, capta sus datos y solicita reuniones. Aquí eliges su nombre y defines qué dice y qué sabe.`">
            <template #actions>
                <Badge :variant="form.enabled ? 'default' : 'secondary'"><Bot class="size-3.5" /> {{ form.enabled ? 'Activo' : 'Desactivado' }}</Badge>
            </template>
        </PageHeader>

        <div class="grid gap-3 rounded-2xl border bg-card p-4 text-sm text-muted-foreground md:grid-cols-3">
            <p class="flex items-start gap-3"><ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" /><span><strong class="text-foreground">Sin acceso al CRM.</strong> El asistente no puede leer clientes, propuestas ni configuración. Solo escribe lo que el visitante le entrega (contacto y solicitud de reunión).</span></p>
            <p class="flex items-start gap-3"><ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" /><span><strong class="text-foreground">Solo lo que tú autorizas.</strong> Responde con el «Conocimiento autorizado» y los datos de «Datos de mi empresa». Si no lo sabe, lo dice y ofrece contacto.</span></p>
            <p class="flex items-start gap-3"><ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" /><span><strong class="text-foreground">Reglas de seguridad fijas.</strong> Se agregan siempre al final y no se pueden editar. Pide consentimiento antes de guardar datos y las reuniones quedan «por confirmar».</span></p>
        </div>

        <p v-if="!aiAvailable" class="flex items-center gap-2 rounded-xl bg-destructive/10 p-3 text-xs text-destructive"><CircleAlert class="size-4" /> No hay un proveedor de IA activo. Configúralo en «Proveedores» para que el asistente pueda responder.</p>

        <form class="grid gap-6 rounded-2xl border bg-card p-4 md:p-6" @submit.prevent="save">
            <label class="flex items-start justify-between gap-4 text-sm">
                <span><span class="font-medium">{{ form.name || 'El asistente' }} activo en el sitio web</span><br /><span class="text-xs text-muted-foreground">Si está desactivado, el botón del asistente no responde y el sitio invita a escribir por correo. Los últimos 30 días: {{ usage.runs }} respuestas, {{ usage.errors }} con error.</span></span>
                <Switch :model-value="form.enabled" @update:model-value="(v: boolean) => (form.enabled = v)" />
            </label>

            <FormField label="Nombre del asistente" for="name" :error="form.errors.name" hint="Cómo se presenta ante los visitantes (por ejemplo Sofía, Luna, Max…). Se aplica al saludo, al prompt y a las notas de los clientes que capte.">
                <Input id="name" v-model="form.name" maxlength="40" class="max-w-xs" />
            </FormField>

            <FormField label="Saludo inicial" for="greeting" :error="form.errors.greeting" hint="Primer mensaje que ve el visitante al abrir el chat. Puedes usar {nombre} y {empresa}. Vacío = saludo predeterminado.">
                <Textarea id="greeting" v-model="form.greeting" rows="2" maxlength="400" />
            </FormField>

            <FormField label="Prompt (personalidad y flujo)" for="prompt" :error="form.errors.prompt" :hint="`Cómo es el asistente, qué hace y cómo conversa. Puedes usar {nombre}, {empresa}, {correo} y {privacidad}. Máximo 6.000 caracteres. ${settings.prompt_is_default ? 'Estás usando el texto predeterminado.' : 'Personalizado.'}`">
                <Textarea id="prompt" v-model="form.prompt" rows="14" maxlength="6000" />
                <div><Button type="button" variant="outline" size="sm" @click="form.prompt = defaultPrompt"><RotateCcw /> Restaurar prompt predeterminado</Button></div>
            </FormField>

            <FormField label="Conocimiento autorizado" for="knowledge" :error="form.errors.knowledge" hint="Lo ÚNICO que el asistente puede afirmar sobre la empresa: servicios y en qué consisten, cómo trabajan, equipo, horarios, diferenciadores, preguntas frecuentes. Redáctalo tú: si algo no está aquí, no lo inventa. No pongas información interna ni datos de clientes.">
                <Textarea id="knowledge" v-model="form.knowledge" rows="16" maxlength="20000" placeholder="Ej.:&#10;SERVICIOS&#10;- Desarrollo web a medida: …&#10;&#10;PREGUNTAS FRECUENTES&#10;- ¿Cuánto demora un proyecto? …" />
            </FormField>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border bg-muted/30 p-3 text-xs">
                    <p class="mb-1 font-medium text-foreground">Datos oficiales que el asistente entrega (de «Datos de mi empresa»)</p>
                    <pre class="whitespace-pre-wrap font-sans text-muted-foreground">{{ contact }}</pre>
                </div>
                <details class="rounded-xl border bg-muted/30 p-3 text-xs">
                    <summary class="cursor-pointer font-medium text-foreground">Reglas de seguridad fijas (solo lectura)</summary>
                    <pre class="mt-2 whitespace-pre-wrap font-sans text-muted-foreground">{{ safety }}</pre>
                </details>
            </div>

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted-foreground">Límites por conversación: {{ limits.messages }} mensajes de hasta {{ limits.chars }} caracteres. Las conversaciones no se guardan; solo la nota del cliente cuando deja sus datos.</p>
                <Button type="submit" :disabled="form.processing">Guardar</Button>
            </div>
        </form>
    </div>
</template>
