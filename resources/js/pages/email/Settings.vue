<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, CheckCircle2, Copy, FlaskConical, Send, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { HttpError, sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { settings as settingsRoute } from '@/routes/email';
import { test as testRoute, update } from '@/routes/email/settings';

type S = {
    provider: 'resend' | 'log'; forced_log: boolean; has_api_key: boolean; api_key_hint: string | null; api_key_source: 'crm' | 'env' | null;
    has_webhook_secret: boolean; from_name: string; from_email: string | null; reply_to: string | null; company_name: string; footer_address: string | null;
};
const props = defineProps<{ settings: S; webhookUrl: string; appUrl: string; events: string[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Email · Configuración', href: settingsRoute() }] } });

const form = useForm({
    mode: props.settings.forced_log ? 'log' : 'resend',
    resend_api_key: '',
    webhook_secret: '',
    clear_api_key: false,
    clear_webhook_secret: false,
    from_name: props.settings.from_name ?? '',
    from_email: props.settings.from_email ?? '',
    reply_to: props.settings.reply_to ?? '',
    company_name: props.settings.company_name ?? '',
    footer_address: props.settings.footer_address ?? '',
});
const save = () => form.submit(update(), { preserveScroll: true, onSuccess: () => form.reset('resend_api_key', 'webhook_secret', 'clear_api_key', 'clear_webhook_secret') });

const to = ref('');
const testing = ref(false);
const result = ref<{ ok: boolean; text: string } | null>(null);
const sendTest = async () => {
    testing.value = true;
    result.value = null;
    try {
        const r = await sendJson<{ provider: string }>('POST', testRoute().url, { to: to.value });
        result.value = { ok: true, text: r.provider === 'log' ? 'Modo de prueba: no se envió realmente (sin API key o modo «solo registrar»).' : `Enviado con Resend a ${to.value}. Revisa tu bandeja.` };
    } catch (e) {
        const b = e instanceof HttpError ? (e.body as { error?: string; message?: string } | null) : null;
        result.value = { ok: false, text: b?.error ?? b?.message ?? 'No se pudo enviar.' };
    } finally {
        testing.value = false;
    }
};

const copied = ref(false);
const copy = async () => {
    await navigator.clipboard.writeText(props.webhookUrl);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
};
const appUrlOk = !/localhost|127\.0\.0\.1/.test(props.appUrl);
</script>

<template>
    <Head title="Email · Configuración" />

    <div class="flex max-w-4xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Configuración de email" description="Conecta Resend, define el remitente y activa el seguimiento de entregas.">
            <template #actions>
                <Badge :class="settings.provider === 'resend' ? 'border-transparent bg-brand-green/10 text-brand-green' : 'border-transparent bg-[#FFA165]/20 text-[#9A4B00]'">
                    {{ settings.provider === 'resend' ? 'Resend conectado' : 'Modo prueba (no envía)' }}
                </Badge>
            </template>
        </PageHeader>

        <form class="flex flex-col gap-5" @submit.prevent="save">
            <DataCard class="p-6">
                <h2 class="font-semibold">Proveedor: Resend</h2>
                <p class="mb-4 text-sm text-muted-foreground">Crea una API key en <a href="https://resend.com/api-keys" target="_blank" rel="noopener" class="text-primary hover:underline">resend.com/api-keys</a> y verifica tu dominio de envío en <a href="https://resend.com/domains" target="_blank" rel="noopener" class="text-primary hover:underline">resend.com/domains</a>.</p>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="API key de Resend" for="key" :error="form.errors.resend_api_key" :hint="settings.has_api_key ? `Guardada: ${settings.api_key_hint} (${settings.api_key_source === 'env' ? 'desde .env' : 'desde el CRM'}). Escribe una nueva para reemplazarla.` : 'Empieza con re_…'">
                        <Input id="key" v-model="form.resend_api_key" type="password" autocomplete="off" placeholder="re_xxxxxxxxxxxx" />
                    </FormField>
                    <FormField label="Modo de envío" for="mode">
                        <NativeSelect id="mode" v-model="form.mode">
                            <option value="resend">Enviar con Resend</option>
                            <option value="log">Solo registrar (pruebas, no envía)</option>
                        </NativeSelect>
                    </FormField>
                    <label v-if="settings.api_key_source === 'crm'" class="flex items-center gap-2 text-xs text-muted-foreground sm:col-span-2"><input v-model="form.clear_api_key" type="checkbox" /> Eliminar la API key guardada</label>
                </div>
            </DataCard>

            <DataCard class="p-6">
                <h2 class="mb-4 font-semibold">Remitente y marca</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="Nombre del remitente" for="fn" :error="form.errors.from_name"><Input id="fn" v-model="form.from_name" placeholder="INTEGRALEADS" /></FormField>
                    <FormField label="Correo del remitente" for="fe" :error="form.errors.from_email" hint="Debe pertenecer a un dominio verificado en Resend."><Input id="fe" v-model="form.from_email" type="email" placeholder="hola@integraleads.cl" /></FormField>
                    <FormField label="Responder a (reply-to)" for="rt" :error="form.errors.reply_to"><Input id="rt" v-model="form.reply_to" type="email" placeholder="ventas@integraleads.cl" /></FormField>
                    <FormField label="Nombre de la empresa" for="cn" :error="form.errors.company_name" hint="Se usa en el pie y en la página de baja."><Input id="cn" v-model="form.company_name" /></FormField>
                    <FormField label="Dirección postal (pie de los boletines)" for="fa" :error="form.errors.footer_address" class="sm:col-span-2"><Input id="fa" v-model="form.footer_address" placeholder="Av. Providencia 1234, Santiago, Chile" /></FormField>
                </div>
            </DataCard>

            <DataCard class="p-6">
                <h2 class="font-semibold">Seguimiento de entregas (webhook)</h2>
                <p class="mb-4 text-sm text-muted-foreground">Para ver entregados, rebotes y spam: en <a href="https://resend.com/webhooks" target="_blank" rel="noopener" class="text-primary hover:underline">resend.com/webhooks</a> agrega esta URL, marca los eventos y pega aquí el <em>signing secret</em> (<code>whsec_…</code>).</p>
                <div class="grid gap-4">
                    <div class="flex items-center gap-2 rounded-xl bg-muted/60 px-3 py-2">
                        <code class="min-w-0 flex-1 truncate text-xs">{{ webhookUrl }}</code>
                        <Button type="button" variant="ghost" size="icon-sm" @click="copy"><Check v-if="copied" class="text-brand-green" /><Copy v-else /></Button>
                    </div>
                    <p v-if="!appUrlOk" class="flex items-start gap-2 rounded-xl bg-[#FFA165]/10 p-3 text-xs text-[#7A3A00]"><TriangleAlert class="mt-0.5 size-4 shrink-0" />Hoy <code>APP_URL</code> apunta a <code>{{ appUrl }}</code>. Los enlaces de seguimiento, la baja y el webhook necesitan la URL pública del CRM: cámbiala en el archivo .env.</p>
                    <div class="flex flex-wrap gap-1.5"><span v-for="e in events" :key="e" class="rounded bg-muted px-2 py-0.5 font-mono text-[11px]">{{ e }}</span></div>
                    <FormField label="Signing secret del webhook" for="ws" :hint="settings.has_webhook_secret ? 'Hay un secret guardado. Escribe uno nuevo para reemplazarlo.' : 'Sin secret, el webhook rechaza todas las llamadas.'">
                        <Input id="ws" v-model="form.webhook_secret" type="password" autocomplete="off" placeholder="whsec_…" />
                    </FormField>
                </div>
            </DataCard>

            <div class="flex justify-end"><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar configuración</Button></div>
        </form>

        <DataCard class="p-6">
            <h2 class="flex items-center gap-2 font-semibold"><FlaskConical class="size-4 text-primary" /> Probar la conexión</h2>
            <p class="mb-4 text-sm text-muted-foreground">Guarda primero la configuración y luego envía un correo de prueba.</p>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="sendTest">
                <FormField label="Enviar a" for="to" class="w-72"><Input id="to" v-model="to" type="email" required /></FormField>
                <Button type="submit" :disabled="testing || !to"><Spinner v-if="testing" /><Send v-else /> Enviar prueba</Button>
            </form>
            <p v-if="result" :class="cn('mt-4 flex items-start gap-2 rounded-xl p-3 text-sm', result.ok ? 'bg-brand-green/10 text-brand-green' : 'bg-destructive/10 text-destructive')">
                <CheckCircle2 v-if="result.ok" class="mt-0.5 size-4 shrink-0" /><TriangleAlert v-else class="mt-0.5 size-4 shrink-0" />{{ result.text }}
            </p>
        </DataCard>
    </div>
</template>
