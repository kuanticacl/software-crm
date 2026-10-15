<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink, Search } from '@lucide/vue';
import { ref } from 'vue';
import DataCard from '@/components/DataCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebouncedFilters } from '@/composables/useDebouncedFilters';
import { messageTone } from '@/lib/emailUi';
import { formatDateTime } from '@/lib/format';
import { sendJson } from '@/lib/http';
import { index, show } from '@/routes/messages';
import type { Paginated } from '@/types';

type Row = { id: number; kind: string; to_email: string; to_name: string | null; subject: string | null; status: string; error: string | null; open_count: number; click_count: number; created_at: string; sent_at: string | null; campaign: string | null; template: string | null };
type Detail = Row & { uuid: string; provider: string | null; provider_id: string | null; variables: Record<string, unknown> | null; view_url: string; lead: { id: number; name: string } | null; events: { id: number; type: string; data: Record<string, unknown> | null; occurred_at: string }[] };

const props = defineProps<{
    messages: Paginated<Row>; filters: Record<string, string | undefined>; statuses: Record<string, string>; kinds: Record<string, string>;
    campaigns: { id: number; name: string }[]; templates: { id: number; name: string }[];
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Historial de envíos', href: index() }] } });

const filters = ref({ q: props.filters.q ?? '', status: props.filters.status ?? '', kind: props.filters.kind ?? '', campaign: props.filters.campaign ?? '', template: props.filters.template ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
useDebouncedFilters(index().url, filters, ['messages', 'filters']);

const open = ref(false);
const detail = ref<Detail | null>(null);
const openRow = async (r: Row) => {
    open.value = true;
    detail.value = null;
    detail.value = await sendJson<Detail>('GET', show(r.id).url);
};
const eventLabel: Record<string, string> = { sent: 'Enviado', delivered: 'Entregado', opened: 'Abierto', clicked: 'Clic', bounced: 'Rebotó', complained: 'Marcado como spam', failed: 'Falló', unsubscribed: 'Se dio de baja', suppressed: 'Omitido', delivery_delayed: 'Entrega demorada' };
</script>

<template>
    <Head title="Historial de envíos" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Historial de envíos" description="Todos los correos enviados por boletines, API y automatizaciones, con su estado de entrega." />

        <DataCard>
            <div class="grid gap-3 border-b p-4 md:grid-cols-3 xl:grid-cols-7">
                <div class="relative xl:col-span-2"><Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" /><Input v-model="filters.q" placeholder="Correo o asunto…" class="pl-9" /></div>
                <NativeSelect v-model="filters.status"><option value="">Todos los estados</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></NativeSelect>
                <NativeSelect v-model="filters.kind"><option value="">Todos los orígenes</option><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></NativeSelect>
                <NativeSelect v-model="filters.campaign"><option value="">Todos los boletines</option><option v-for="c in campaigns" :key="c.id" :value="c.id">{{ c.name }}</option></NativeSelect>
                <NativeSelect v-model="filters.template"><option value="">Todas las plantillas</option><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option></NativeSelect>
                <div class="flex items-center gap-1"><Input v-model="filters.from" type="date" /><Input v-model="filters.to" type="date" /></div>
            </div>
            <Table>
                <TableHeader><TableRow class="hover:bg-transparent"><TableHead>Destinatario</TableHead><TableHead>Asunto</TableHead><TableHead>Origen</TableHead><TableHead>Estado</TableHead><TableHead class="text-right">Aper.</TableHead><TableHead class="text-right">Clics</TableHead><TableHead>Fecha</TableHead></TableRow></TableHeader>
                <TableBody>
                    <TableEmpty v-if="!messages.data.length" :colspan="7">No hay mensajes con esos filtros.</TableEmpty>
                    <TableRow v-for="m in messages.data" :key="m.id" class="cursor-pointer" @click="openRow(m)">
                        <TableCell><p class="font-medium">{{ m.to_email }}</p><p v-if="m.to_name" class="text-xs text-muted-foreground">{{ m.to_name }}</p></TableCell>
                        <TableCell class="max-w-64 truncate text-muted-foreground">{{ m.subject ?? '—' }}</TableCell>
                        <TableCell class="text-xs text-muted-foreground">{{ m.campaign ?? m.template ?? kinds[m.kind] }}</TableCell>
                        <TableCell><Badge :class="['border-transparent', messageTone[m.status]]" :title="m.error ?? ''">{{ statuses[m.status] }}</Badge></TableCell>
                        <TableCell class="text-right tabular-nums">{{ m.open_count || '—' }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ m.click_count || '—' }}</TableCell>
                        <TableCell class="text-muted-foreground">{{ formatDateTime(m.sent_at ?? m.created_at) }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            <Pagination :paginator="messages" />
        </DataCard>
    </div>

    <Sheet v-model:open="open">
        <SheetContent side="right" class="w-full gap-0 overflow-hidden p-0 sm:max-w-xl">
            <SheetHeader class="shrink-0 border-b px-5 py-4 pr-12"><SheetTitle class="truncate">{{ detail?.to_email ?? 'Cargando…' }}</SheetTitle><SheetDescription class="truncate">{{ detail?.subject }}</SheetDescription></SheetHeader>
            <div class="scroll-cues grid min-h-0 flex-1 content-start gap-5 p-4 [overflow-wrap:anywhere] sm:p-5">
                <Skeleton v-if="!detail" class="h-40 w-full rounded-2xl" />
                <template v-else>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :class="['border-transparent', messageTone[detail.status]]">{{ statuses[detail.status] }}</Badge>
                        <span class="text-xs text-muted-foreground">{{ kinds[detail.kind] }}{{ detail.campaign ? ' · ' + (detail as any).campaign.name : '' }}{{ detail.provider ? ' · ' + detail.provider : '' }}</span>
                        <a :href="detail.view_url" target="_blank" rel="noopener" class="ml-auto inline-flex items-center gap-1 text-xs text-primary hover:underline"><ExternalLink class="size-3" /> Ver el correo</a>
                    </div>
                    <p v-if="detail.error" class="rounded-xl bg-destructive/10 p-3 text-sm text-destructive">{{ detail.error }}</p>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-muted-foreground">ID del proveedor</dt><dd class="truncate font-mono text-xs">{{ detail.provider_id ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted-foreground">Aperturas / clics</dt><dd>{{ detail.open_count }} / {{ detail.click_count }}</dd></div>
                    </dl>
                    <div>
                        <h4 class="mb-2 text-sm font-semibold">Línea de tiempo</h4>
                        <ol class="relative grid gap-3 border-l pl-5">
                            <li v-for="e in detail.events" :key="e.id" class="relative"><span class="absolute -left-[1.55rem] size-2.5 rounded-full bg-primary ring-4 ring-background" /><p class="text-sm font-medium">{{ eventLabel[e.type] ?? e.type }}</p><p class="text-xs text-muted-foreground">{{ formatDateTime(e.occurred_at) }}<template v-if="e.data?.url"> · {{ e.data.url }}</template></p></li>
                            <li v-if="!detail.events.length" class="text-sm text-muted-foreground">Sin eventos aún.</li>
                        </ol>
                    </div>
                    <div v-if="detail.variables && Object.keys(detail.variables).length">
                        <h4 class="mb-2 text-sm font-semibold">Variables usadas</h4>
                        <pre class="max-h-56 overflow-auto rounded-xl bg-muted/60 p-3 text-[11px]">{{ JSON.stringify(detail.variables, null, 2) }}</pre>
                    </div>
                </template>
            </div>
        </SheetContent>
    </Sheet>
</template>
