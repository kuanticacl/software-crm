<script setup lang="ts">
import { Sparkles, TriangleAlert } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { Design } from '@/lib/emailBuilder';
import { HttpError, sendJson } from '@/lib/http';

export type AiDesignResult = { subject: string; preheader: string; design: Design; notes: string };

const props = defineProps<{ open: boolean; hasContent: boolean }>();
const emit = defineEmits<{ 'update:open': [boolean]; apply: [AiDesignResult] }>();

const form = reactive({ brief: '', kind: 'marketing', tone: 'Cercano y profesional', length: 'medium', cta_label: '', cta_url: '', images: '' });
const busy = ref(false);
const error = ref('');
const errors = ref<Record<string, string>>({});

watch(() => props.open, (o) => {
    if (o) {
        error.value = '';
        errors.value = {};
    }
});

const examples = [
    'Boletín mensual con 3 novedades de tecnología (web, automatización e IA) y una invitación a agendar una reunión.',
    'Correo de agradecimiento para quien escribe desde el formulario del sitio: confirmar recepción y avisar que un ejecutivo lo contactará.',
    'Invitación a un webinar sobre cómo automatizar procesos con inteligencia artificial.',
];

const generate = async () => {
    busy.value = true;
    error.value = '';
    errors.value = {};
    try {
        const images = form.images.split('\n').map((s) => s.trim()).filter(Boolean);
        const res = await sendJson<AiDesignResult>('POST', '/email/ai/design', {
            ...form,
            cta_label: form.cta_label || null,
            cta_url: form.cta_url || null,
            images: images.length ? images : null,
        });
        emit('apply', res);
        emit('update:open', false);
    } catch (e) {
        if (e instanceof HttpError) {
            errors.value = e.fieldErrors;
            error.value = Object.keys(e.fieldErrors).length ? '' : (e.body?.message ?? 'No se pudo generar el correo.');
        } else {
            error.value = 'No se pudo conectar con el servidor.';
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2"><Sparkles class="size-5 text-primary" /> Asistente de IA para mailings</DialogTitle>
                <DialogDescription>Cuéntame qué quieres comunicar. Redacto el asunto y el contenido; el diseño siempre usa la identidad oficial de INTEGRALEADS (logo, naranja, tipografía y botones).</DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <FormField label="¿De qué trata el correo?" :error="errors.brief">
                    <Textarea v-model="form.brief" rows="4" placeholder="Ej: Invitar a gerentes de operaciones a una demo de automatización de procesos, destacando el ahorro de tiempo y la integración con sus sistemas." />
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <button v-for="(ex, i) in examples" :key="i" type="button" class="rounded-full border bg-muted/40 px-2.5 py-1 text-[11px] text-muted-foreground transition hover:border-primary/50 hover:text-foreground" @click="form.brief = ex">Ejemplo {{ i + 1 }}</button>
                    </div>
                </FormField>

                <div class="grid gap-3 sm:grid-cols-3">
                    <FormField label="Tipo">
                        <NativeSelect v-model="form.kind"><option value="marketing">Boletín / promoción</option><option value="transactional">Transaccional (respuesta)</option></NativeSelect>
                    </FormField>
                    <FormField label="Tono">
                        <NativeSelect v-model="form.tone"><option value="Cercano y profesional">Cercano</option><option>Formal</option><option>Directo y comercial</option><option>Inspirador</option></NativeSelect>
                    </FormField>
                    <FormField label="Extensión">
                        <NativeSelect v-model="form.length"><option value="short">Corto</option><option value="medium">Medio</option><option value="long">Completo</option></NativeSelect>
                    </FormField>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField label="Texto del botón (opcional)" :error="errors.cta_label"><Input v-model="form.cta_label" placeholder="Agenda una demo" /></FormField>
                    <FormField label="Enlace del botón (opcional)" :error="errors.cta_url"><Input v-model="form.cta_url" placeholder="https://www.integraleads.cl" /></FormField>
                </div>

                <FormField label="Imágenes (opcional)" :error="errors.images" hint="Una URL https por línea. La IA solo usa las que entregues; no inventa imágenes.">
                    <Textarea v-model="form.images" rows="2" placeholder="https://…/imagen.jpg" />
                </FormField>

                <p v-if="hasContent" class="flex items-start gap-2 rounded-xl bg-[#FFA165]/15 px-3 py-2 text-xs text-[#9A4B00]"><TriangleAlert class="mt-0.5 size-4 shrink-0" /> Esto reemplazará el diseño actual del editor (puedes deshacer cerrando sin guardar).</p>
                <p v-if="error" class="rounded-xl bg-destructive/10 px-3 py-2 text-sm text-destructive">{{ error }}</p>
                <p class="text-[11px] text-muted-foreground">La IA no recibe datos de prospectos ni de clientes. Revisa siempre el resultado antes de enviar.</p>
            </div>

            <DialogFooter>
                <Button variant="ghost" :disabled="busy" @click="emit('update:open', false)">Cancelar</Button>
                <Button :disabled="busy || form.brief.trim().length < 10" @click="generate"><Spinner v-if="busy" /><Sparkles v-else /> {{ busy ? 'Generando…' : 'Generar correo' }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
