<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { Ban, CalendarClock, Copy, MailCheck, MailOpen, MailX, MousePointerClick, Pause, Pencil, Play, Search, UserMinus } from '@lucide/vue';
import { computed, ref } from 'vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { usePermissions } from '@/composables/usePermissions';
import { campaignTone, messageTone } from '@/lib/emailUi';
import { formatDateTime, timeAgo } from '@/lib/format';
import { cancel, duplicate, edit, index, pause, resume, show } from '@/routes/campaigns';
import type { Paginated } from '@/types';

type Recipient = { id: number; to_email: string; to_name: string | null; status: string; sent_at: string | null; first_opened_at: string | null; first_clicked_at: string | null; open_count: number; click_count: number; unsubscribed_at: string | null; error: string | null };
const props = defineProps<{
    campaign: { id: number; name: string; subject: string; status: string; recipients_count: number; scheduled_at: string | null; started_at: string | null; finished_at: string | null; track_opens: boolean; track_clicks: boolean; template: { id: number; name: string } | null; creator: string | null };
    stats: Record<string, number>;
    recipients: Paginated<Recipient>;
    filters: { q?: string; status?: string };
    topLinks: { url: string; count: number }[];
    statuses: Record<string, string>;
    messageStatuses: Record<string, string>;
    provider: string;
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Boletines', href: index() }] } });

const { can } = usePermissions();
const filters = ref({ q: props.filters.q ?? '', status: props.filters.status ?? '' });
useDebouncedFilters(show(props.campaign.id).url, filters, ['recipients', 'filters']);

// Mientras se envía, se refrescan las métricas solas.
useIntervalFn(() => { if (['sending', 'scheduled'].includes(props.campaign.status)) router.reload({ only: ['campaign', 'stats', 'recipients'] }); }, 5000);

const progress = computed(() => (props.campaign.recipients_count ? Math.round(((props.stats.total - props.stats.pending) / props.campaign.recipients_count) * 100) : 0));
const kpis = computed(() => [
    { label: 'Enviados', value: props.stats.sent, sub: `${props.campaign.recipients_count} destinatarios`, icon: MailCheck, color: '#1AA0E4' },
    { label: 'Entregados', value: props.stats.delivered, sub: props.stats.sent ? `${Math.round((props.stats.delivered / props.stats.sent) * 100)}%` : '', icon: MailCheck, color: '#0D9F85' },
    { label: 'Aperturas', value: props.stats.opened, sub: `${props.stats.open_rate}% tasa`, icon: MailOpen, color: '#3B2F2F' },
    { label: 'Clics', value: props.stats.clicked, sub: `${props.stats.click_rate}% tasa`, icon: MousePointerClick, color: '#A9606B' },
    { label: 'Rebotes', value: props.stats.bounced, sub: `${props.stats.bounce_rate}%`, icon: MailX, color: '#DC2626' },
    { label: 'Bajas', value: props.stats.unsubscribed, sub: `${props.stats.complained} spam`, icon: UserMinus, color: '#8A8A8A' },
]);
const post = (url: string) => router.post(url, {}, { preserveScroll: true });
const cancelSend = () => {
    if (window.confirm('¿Cancelar el envío? Lo ya enviado no se puede revertir.')) post(cancel(props.campaign.id).url);
};
</script>

<template>
    <Head :title="campaign.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="campaign.name" :description="`Asunto: ${campaign.subject}`">
            <template #actions>
                <Badge :class="['border-transparent', campaignTone[campaign.status]]">{{ statuses[campaign.status] }}</Badge>
                <Button v-if="can('campaigns.create') && ['draft', 'scheduled'].includes(campaign.status)" variant="outline" as-child><Link :href="edit(campaign.id)"><Pencil /> Editar</Link></Button>
                <Button v-if="can('campaigns.send') && campaign.status === 'sending'" variant="outline" @click="post(pause(campaign.id).url)"><Pause /> Pausar</Button>
                <Button v-if="can('campaigns.send') && campaign.status === 'paused'" variant="outline" @click="post(resume(campaign.id).url)"><Play /> Reanudar</Button>
                <Button v-if="can('campaigns.send') && ['scheduled', 'sending', 'paused'].includes(campaign.status)" variant="outline" class="text-destructive hover:text-destructive" @click="cancelSend"><Ban /> Cancelar</Button>
                <Button v-if="can('campaigns.create')" variant="outline" @click="post(duplicate(campaign.id).url)"><Copy /> Duplicar</Button>
            </template>
        </PageHeader>

        <p v-if="provider === 'log'" class="rounded-xl border border-[#FFA165]/50 bg-[#FFA165]/10 p-3 text-sm text-[#7A3A00]">El CRM está en <strong>modo prueba</strong>: los envíos se registran pero no salen realmente. Conecta Resend en Email → Configuración.</p>
        <p v-if="campaign.status === 'scheduled' && campaign.scheduled_at" class="flex items-center gap-2 rounded-xl bg-brand-blue/10 p-3 text-sm text-brand-blue"><CalendarClock class="size-4" /> Programado para {{ formatDateTime(campaign.scheduled_at) }} ({{ timeAgo(campaign.scheduled_at) }}).</p>

        <div v-if="['sending', 'paused'].includes(campaign.status)" class="rounded-2xl border bg-card p-4">
            <div class="mb-2 flex justify-between text-sm"><span class="font-medium">{{ campaign.status === 'sending' ? 'Enviando…' : 'Pausado' }}</span><span class="text-muted-foreground">{{ progress }}% · quedan {{ stats.pending }}</span></div>
            <div class="h-2 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary transition-all" :style="{ width: progress + '%' }" /></div>
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <div v-for="k in kpis" :key="k.label" class="flex items-center gap-3 rounded-2xl border bg-card p-4">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl [&_svg]:size-5" :style="{ backgroundColor: k.color + '1A', color: k.color }"><component :is="k.icon" /></div>
                <div class="min-w-0"><p class="text-2xl leading-none font-bold tabular-nums">{{ k.value.toLocaleString('es-CL') }}</p><p class="mt-1 truncate text-xs text-muted-foreground">{{ k.label }} · {{ k.sub }}</p></div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <DataCard class="p-5 lg:col-span-2">
                <h3 class="mb-4 font-semibold">Embudo de envío</h3>
                <div class="grid gap-3">
                    <div v-for="[label, value, color] in ([['Destinatarios', campaign.recipients_count, '#8A8A8A'], ['Enviados', stats.sent, '#1AA0E4'], ['Entregados', stats.delivered, '#0D9F85'], ['Abrieron', stats.opened, '#3B2F2F'], ['Hicieron clic', stats.clicked, '#A9606B']] as [string, number, string][])" :key="label">
                        <div class="mb-1 flex justify-between text-xs"><span>{{ label }}</span><span class="text-muted-foreground tabular-nums">{{ value.toLocaleString('es-CL') }}</span></div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full transition-all" :style="{ width: (campaign.recipients_count ? (value / campaign.recipients_count) * 100 : 0) + '%', backgroundColor: color }" /></div>
                    </div>
                </div>
                <p class="mt-4 text-[11px] text-muted-foreground">Las aperturas pueden estar sobreestimadas por la protección de privacidad de Apple Mail y subestimadas si el cliente bloquea imágenes. Los clics son más confiables.</p>
            </DataCard>
            <DataCard class="p-5">
                <h3 class="mb-3 font-semibold">Enlaces más clicados</h3>
                <p v-if="!topLinks.length" class="text-sm text-muted-foreground">Aún no hay clics{{ campaign.track_clicks ? '' : ' (el seguimiento de clics está desactivado)' }}.</p>
                <ul class="grid gap-2"><li v-for="l in topLinks" :key="l.url" class="flex items-center justify-between gap-3 text-sm"><a :href="l.url" target="_blank" rel="noopener" class="truncate text-primary hover:underline" :title="l.url">{{ l.url.replace(/^https?:\/\//, '') }}</a><span class="font-semibold tabular-nums">{{ l.count }}</span></li></ul>
            </DataCard>
        </div>

        <DataCard>
            <div class="flex flex-col gap-3 border-b p-4 md:flex-row">
                <div class="relative flex-1"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="filters.q" placeholder="Buscar destinatario…" class="pl-9" /></div>
                <NativeSelect v-model="filters.status" class="md:w-56">
                    <option value="">Todos</option>
                    <option value="opened">Abrieron</option><option value="clicked">Hicieron clic</option><option value="unsubscribed">Se dieron de baja</option>
                    <option v-for="(l, k) in messageStatuses" :key="k" :value="k">{{ l }}</option>
                </NativeSelect>
            </div>
            <Table>
                <TableHeader><TableRow class="hover:bg-transparent"><TableHead>Destinatario</TableHead><TableHead>Estado</TableHead><TableHead>Enviado</TableHead><TableHead class="text-right">Aperturas</TableHead><TableHead class="text-right">Clics</TableHead></TableRow></TableHeader>
                <TableBody>
                    <TableEmpty v-if="!recipients.data.length" :colspan="5">Sin destinatarios que coincidan.</TableEmpty>
                    <TableRow v-for="r in recipients.data" :key="r.id">
                        <TableCell><p class="font-medium">{{ r.to_email }}</p><p v-if="r.to_name" class="text-xs text-muted-foreground">{{ r.to_name }}</p></TableCell>
                        <TableCell><Badge :class="['border-transparent', messageTone[r.status]]" :title="r.error ?? ''">{{ messageStatuses[r.status] }}</Badge><Badge v-if="r.unsubscribed_at" class="ml-1 border-transparent bg-muted text-muted-foreground">Baja</Badge></TableCell>
                        <TableCell class="text-muted-foreground">{{ r.sent_at ? formatDateTime(r.sent_at) : '—' }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ r.open_count || '—' }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ r.click_count || '—' }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="recipients" />
        </DataCard>
    </div>
</template>
