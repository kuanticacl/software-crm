<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, Globe, Mail, MapPin, Monitor, Phone } from '@lucide/vue';
import { computed } from 'vue';
import DataCard from '@/components/DataCard.vue';
import SourceIcon from '@/components/SourceIcon.vue';
import { whatsappUrl } from '@/lib/leadUi';
import type { PanelData } from '@/types';

const props = defineProps<{ data: PanelData }>();
const lead = computed(() => props.data.lead);

const utmEntries = computed(() => Object.entries(lead.value.utm).filter(([, v]) => v));
const location = computed(() => [lead.value.capture.city, lead.value.capture.region, lead.value.capture.country].filter(Boolean).join(', '));
const mapUrl = computed(() =>
    lead.value.capture.latitude != null && lead.value.capture.longitude != null
        ? `https://www.openstreetmap.org/?mlat=${lead.value.capture.latitude}&mlon=${lead.value.capture.longitude}#map=12/${lead.value.capture.latitude}/${lead.value.capture.longitude}`
        : null,
);
const device = computed(() => {
    const ua = String(lead.value.capture.user_agent ?? '');
    if (!ua) return null;
    const kind = /mobile|iphone|android/i.test(ua) ? 'Móvil' : /ipad|tablet/i.test(ua) ? 'Tablet' : 'Escritorio';
    const browser = /edg\//i.test(ua) ? 'Edge' : /chrome|crios/i.test(ua) ? 'Chrome' : /firefox|fxios/i.test(ua) ? 'Firefox' : /safari/i.test(ua) ? 'Safari' : '';
    return [kind, browser].filter(Boolean).join(' · ');
});
const metaEntries = computed(() => Object.entries(lead.value.meta ?? {}));
const showValue = (type: string, v: unknown) => (type === 'checkbox' ? (v ? 'Sí' : 'No') : String(v));
const wa = computed(() => whatsappUrl(lead.value.phone));
</script>

<template>
    <div class="flex flex-col gap-4">
        <DataCard class="p-4">
            <h3 class="mb-3 text-sm font-semibold">Contacto</h3>
            <ul class="grid gap-2.5 text-sm">
                <li v-if="lead.email" class="flex items-center gap-2.5"><Mail class="size-4 text-muted-foreground" /><a :href="`mailto:${lead.email}`" class="truncate hover:text-primary">{{ lead.email }}</a></li>
                <li v-if="lead.phone" class="flex items-center gap-2.5">
                    <Phone class="size-4 text-muted-foreground" /><a :href="`tel:${lead.phone}`" class="hover:text-primary">{{ lead.phone }}</a>
                    <a v-if="wa" :href="wa" target="_blank" rel="noopener" class="text-xs text-[#25D366] hover:underline">WhatsApp</a>
                </li>
                <li v-if="lead.company" class="flex items-center gap-2.5"><Building2 class="size-4 text-muted-foreground" />{{ lead.company }}</li>
                <li v-if="lead.client" class="flex items-center gap-2.5"><Building2 class="size-4 text-primary" /><span>Cliente: <Link :href="`/clients/${lead.client.id}`" class="font-medium hover:text-primary">{{ lead.client.name }}</Link></span></li>
            </ul>
            <p v-if="lead.message" class="mt-3 border-t pt-3 text-sm whitespace-pre-line text-muted-foreground">{{ lead.message }}</p>
        </DataCard>

        <DataCard class="p-4">
            <h3 class="mb-3 text-sm font-semibold">Origen y campaña</h3>
            <span v-if="lead.source" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium text-white [&_svg]:size-3.5" :style="{ backgroundColor: lead.source.color }">
                <SourceIcon :name="lead.source.icon" />{{ lead.source.name }}
            </span>
            <dl v-if="utmEntries.length" class="mt-3 grid gap-1.5 text-sm">
                <div v-for="[k, v] in utmEntries" :key="k" class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ k }}</dt><dd class="truncate font-medium">{{ v }}</dd></div>
            </dl>
            <p v-else class="mt-3 text-xs text-muted-foreground">Sin parámetros UTM.</p>
        </DataCard>

        <DataCard class="p-4">
            <h3 class="mb-3 text-sm font-semibold">Datos de captura</h3>
            <ul class="grid gap-2.5 text-sm">
                <li v-if="location" class="flex items-start gap-2.5"><MapPin class="mt-0.5 size-4 shrink-0 text-muted-foreground" /><span>{{ location }}<a v-if="mapUrl" :href="mapUrl" target="_blank" rel="noopener" class="ml-1 text-xs text-primary hover:underline">ver mapa</a></span></li>
                <li v-if="lead.capture.ip_address" class="flex items-center gap-2.5"><Globe class="size-4 shrink-0 text-muted-foreground" /><span>IP <code>{{ lead.capture.ip_address }}</code></span></li>
                <li v-if="device" class="flex items-center gap-2.5" :title="String(lead.capture.user_agent)"><Monitor class="size-4 shrink-0 text-muted-foreground" />{{ device }}</li>
                <li v-if="lead.capture.landing_url" class="break-all"><span class="text-xs text-muted-foreground">Landing</span><br />{{ lead.capture.landing_url }}</li>
                <li v-if="lead.capture.referrer" class="break-all"><span class="text-xs text-muted-foreground">Referrer</span><br />{{ lead.capture.referrer }}</li>
                <li v-if="!location && !lead.capture.ip_address && !device && !lead.capture.landing_url && !lead.capture.referrer" class="text-xs text-muted-foreground">Sin datos de captura (prospecto ingresado manualmente).</li>
            </ul>
            <dl v-if="metaEntries.length" class="mt-3 grid gap-1.5 border-t pt-3 text-xs">
                <div v-for="[k, v] in metaEntries" :key="k" class="flex justify-between gap-3"><dt class="text-muted-foreground">{{ k }}</dt><dd class="max-w-[60%] truncate font-mono">{{ typeof v === 'object' ? JSON.stringify(v) : v }}</dd></div>
            </dl>
        </DataCard>

        <DataCard v-if="data.customFields.length" class="p-4">
            <h3 class="mb-3 text-sm font-semibold">Campos personalizados</h3>
            <dl class="grid gap-2 text-sm">
                <div v-for="f in data.customFields" :key="f.key" class="flex justify-between gap-3">
                    <dt class="text-muted-foreground">{{ f.label }}</dt>
                    <dd class="text-right font-medium">{{ f.value !== null && f.value !== '' ? showValue(f.type, f.value) : '—' }}</dd>
                </div>
            </dl>
        </DataCard>
    </div>
</template>
