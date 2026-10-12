<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, Copy, CopyPlus, ExternalLink, Eye, FileDown, Link2, Mail, Pencil, Send, Trash2, User } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, timeAgo } from '@/lib/format';
import { formatAmount, formatMoney, formatUfValue } from '@/lib/leadUi';
import { destroy, document, duplicate, edit, index, markSent, pdf, send, show, status } from '@/routes/proposals';
import { show as showLead } from '@/routes/leads';
import type { ProposalRow, Recipient } from '@/types';

type Detail = ProposalRow & {
    recipient: Recipient | null; public_url: string; sent_at: string | null; viewed_at: string | null; view_count: number;
    responded_at: string | null; responded_by: string | null; signer_rut: string | null; signature_data: string | null; response_note: string | null; internal_notes: string | null; internal_notes_html: string;
    total_one_time: number; total_monthly: number; total_tax: number; contract_months: number | null;
    tax_labels: { net: string; tax: string | null; gross: string };
};

const props = defineProps<{ proposal: Detail; statuses: Record<string, string>; siblings: ProposalRow[]; can: { create: boolean; send: boolean; delete: boolean } }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Propuestas', href: index() }] } });

const p = computed(() => props.proposal);

const copied = ref(false);
const copyLink = async () => {
    await navigator.clipboard.writeText(p.value.public_url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1800);
    toast.success('Enlace copiado');
};

// ---- envío por correo
const sendOpen = ref(false);
const sendForm = useForm({
    to: props.proposal.recipient?.email ?? '',
    name: props.proposal.recipient?.contact_name ?? '',
    subject: `Propuesta comercial: ${props.proposal.title}`,
    message: `Hola${props.proposal.recipient?.contact_name ? ' ' + props.proposal.recipient.contact_name.split(' ')[0] : ''}, tal como conversamos, te comparto la propuesta con los servicios, plazos e inversión. Cualquier duda la revisamos juntos.`,
});
const submitSend = () => sendForm.submit(send(p.value.id), { preserveScroll: true, onSuccess: () => (sendOpen.value = false) });

const setStatus = (s: string) => router.put(status(p.value.id).url, { status: s }, { preserveScroll: true });
const markAsSent = () => router.post(markSent(p.value.id).url, {}, { preserveScroll: true });
const dup = () => router.post(duplicate(p.value.id).url);
const del = ref(false);
const remove = () => router.delete(destroy(p.value.id).url);
const editable = computed(() => props.can.create && !['accepted', 'rejected'].includes(p.value.status));

const timeline = computed(() => [
    { label: 'Creada', at: p.value.created_at, done: true },
    { label: 'Enviada', at: p.value.sent_at, done: !!p.value.sent_at },
    { label: p.value.view_count ? `Vista por el cliente (${p.value.view_count} ${p.value.view_count === 1 ? 'vez' : 'veces'})` : 'Vista por el cliente', at: p.value.viewed_at, done: !!p.value.viewed_at },
    { label: p.value.status === 'rejected' ? 'Rechazada' : p.value.status === 'changes_requested' ? 'Ajustes solicitados' : p.value.signature_data ? 'Firmada y aceptada' : 'Aceptada', at: p.value.responded_at, done: !!p.value.responded_at },
]);
const money = (n: number) => formatAmount(n, props.proposal.currency);
const clp = (n: number) => formatMoney(n) || '$0';
const ufDay = computed(() => (props.proposal.uf_date ? new Intl.DateTimeFormat('es-CL', { dateStyle: 'long' }).format(new Date(props.proposal.uf_date + 'T12:00:00')) : ''));
</script>

<template>
    <Head :title="proposal.title" />

    <div class="flex flex-col gap-5 p-4 md:p-6">
        <PageHeader :title="proposal.title" :description="`${proposal.number} · ${proposal.company ?? 'Sin cliente'}`">
            <template #actions>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold text-white" :style="{ backgroundColor: proposal.status_color }">{{ proposal.status_label }}</span>
                <Button v-if="editable" variant="outline" as-child><Link :href="edit(proposal.id)"><Pencil /> Editar</Link></Button>
                <Button variant="outline" as-child><a :href="pdf(proposal.id).url"><FileDown /> PDF</a></Button>
                <Button v-if="can.send" @click="sendOpen = true"><Send /> Enviar por correo</Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child><Button variant="outline">Más acciones</Button></DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60">
                        <DropdownMenuItem as-child><a :href="pdf(proposal.id).url"><FileDown /> Descargar PDF</a></DropdownMenuItem>
                        <DropdownMenuItem @select="copyLink"><Link2 /> Copiar enlace público</DropdownMenuItem>
                        <DropdownMenuItem as-child><a :href="proposal.public_url" target="_blank" rel="noopener"><ExternalLink /> Abrir como lo ve el cliente</a></DropdownMenuItem>
                        <DropdownMenuItem v-if="can.create" @select="dup"><CopyPlus /> Nueva versión (duplicar)</DropdownMenuItem>
                        <DropdownMenuItem v-if="can.send && proposal.status === 'draft'" @select="markAsSent"><Check /> Marcar como enviada</DropdownMenuItem>
                        <DropdownMenuItem v-if="can.send && !['accepted', 'rejected'].includes(proposal.status)" @select="setStatus('accepted')"><Check /> Registrar como aceptada</DropdownMenuItem>
                        <DropdownMenuItem v-if="can.send && !['accepted', 'rejected'].includes(proposal.status)" @select="setStatus('rejected')">Registrar como rechazada</DropdownMenuItem>
                        <DropdownMenuItem v-if="can.send && ['accepted', 'rejected'].includes(proposal.status)" @select="setStatus('sent')">Reabrir (volver a «Enviada»)</DropdownMenuItem>
                        <DropdownMenuItem v-if="can.delete" class="text-destructive focus:text-destructive" @select="del = true"><Trash2 /> Eliminar</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <div class="grid gap-4 xl:grid-cols-[1fr_320px]">
            <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <iframe :src="document(proposal.id).url" title="Vista de la propuesta" class="h-[78vh] w-full bg-[#F4F4F4]" />
            </div>

            <div class="flex flex-col gap-4">
                <DataCard class="p-4">
                    <h3 class="mb-3 text-sm font-semibold">Inversión</h3>
                    <dl class="grid gap-1.5 text-sm">
                        <div v-if="proposal.total_one_time" class="flex justify-between"><dt class="text-muted-foreground">Pago único</dt><dd>{{ money(proposal.total_one_time) }}</dd></div>
                        <div v-if="proposal.total_monthly" class="flex justify-between"><dt class="text-muted-foreground">Mensual × {{ proposal.contract_months ?? 1 }}</dt><dd>{{ money(proposal.total_monthly) }}</dd></div>
                        <template v-if="proposal.tax_labels.tax">
                            <div class="flex justify-between border-t pt-1.5"><dt>{{ proposal.tax_labels.net }}</dt><dd class="font-semibold">{{ money(proposal.total_net) }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-muted-foreground">{{ proposal.tax_labels.tax }}</dt><dd class="shrink-0">{{ money(proposal.total_tax) }}</dd></div>
                        </template>
                        <div class="flex justify-between gap-2 rounded-xl bg-primary px-3 py-2 font-bold text-primary-foreground"><dt>{{ proposal.tax_labels.gross }}</dt><dd class="shrink-0">{{ money(proposal.total_gross) }}</dd></div>
                    </dl>
                    <p v-if="proposal.currency === 'UF' && proposal.uf_value" class="mt-3 rounded-xl bg-muted/50 p-3 text-xs text-muted-foreground">≈ <strong class="text-foreground">{{ clp(proposal.total_gross_clp) }}</strong> en pesos<br>Valor UF de referencia: <strong class="text-foreground">{{ formatUfValue(proposal.uf_value) }}</strong> ({{ ufDay }}){{ proposal.status === 'draft' ? ' · se actualiza cada día hasta que se envía' : '' }}</p>
                </DataCard>

                <DataCard class="p-4">
                    <h3 class="mb-3 text-sm font-semibold">Seguimiento</h3>
                    <ol class="grid gap-3">
                        <li v-for="t in timeline" :key="t.label" class="flex items-start gap-3 text-sm">
                            <span :class="['mt-0.5 grid size-5 shrink-0 place-items-center rounded-full', t.done ? 'bg-brand-green text-white' : 'bg-muted']"><Check v-if="t.done" class="size-3" /></span>
                            <span :class="t.done ? '' : 'text-muted-foreground'">{{ t.label }}<span v-if="t.at" class="block text-xs text-muted-foreground">{{ formatDateTime(t.at) }} · {{ timeAgo(t.at) }}</span></span>
                        </li>
                    </ol>
                    <div v-if="proposal.responded_by" class="mt-3 rounded-xl bg-muted/50 p-3 text-xs"><User class="mr-1 inline size-3.5" /><strong>{{ proposal.responded_by }}</strong><span v-if="proposal.signer_rut" class="text-muted-foreground"> · RUT {{ proposal.signer_rut }}</span><span v-if="proposal.response_note" class="block pt-1 text-muted-foreground">{{ proposal.response_note }}</span><img v-if="proposal.signature_data" :src="proposal.signature_data" alt="Firma del cliente" class="mt-2 max-h-16 rounded-lg border bg-white p-1"></div>
                </DataCard>

                <DataCard v-if="proposal.lead || proposal.client" class="p-4">
                    <h3 class="mb-2 text-sm font-semibold">Asociada a</h3>
                    <p v-if="proposal.lead" class="text-sm"><Link :href="showLead(proposal.lead.id)" class="font-medium text-primary hover:underline">Lead: {{ proposal.lead.name }}</Link></p>
                    <p v-if="proposal.client" class="text-sm"><Link :href="`/clients/${proposal.client.id}`" class="font-medium text-primary hover:underline">Cliente: {{ proposal.client.name }}</Link></p>
                </DataCard>

                <DataCard v-if="siblings.length" class="p-4">
                    <h3 class="mb-2 text-sm font-semibold">Otras propuestas del mismo {{ proposal.lead ? 'lead' : 'cliente' }}</h3>
                    <ul class="grid gap-2">
                        <li v-for="s in siblings" :key="s.id">
                            <Link :href="show(s.id)" class="flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-sm transition hover:border-primary/40">
                                <span class="min-w-0"><span class="block truncate font-medium">{{ s.title }}</span><span class="text-xs text-muted-foreground">{{ s.number }} · {{ money(s.total_net) }}</span></span>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold text-white" :style="{ backgroundColor: s.status_color }">{{ s.status_label }}</span>
                            </Link>
                        </li>
                    </ul>
                </DataCard>

                <DataCard v-if="proposal.internal_notes" class="p-4"><h3 class="mb-1 text-sm font-semibold">Notas internas</h3><div class="rich text-sm text-muted-foreground" v-html="proposal.internal_notes_html" /></DataCard>
            </div>
        </div>
    </div>

    <Dialog v-model:open="sendOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader><DialogTitle class="flex items-center gap-2"><Mail class="size-5 text-primary" /> Enviar propuesta por correo</DialogTitle><DialogDescription>Se envía con el diseño de Vivienda en Regla y un botón al enlace de la propuesta. Quedará registrada en el historial del prospecto.</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitSend">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Correo" for="s-to" :error="sendForm.errors.to" required><Input id="s-to" v-model="sendForm.to" type="email" /></FormField>
                    <FormField label="Nombre" for="s-name" :error="sendForm.errors.name"><Input id="s-name" v-model="sendForm.name" /></FormField>
                </div>
                <FormField label="Asunto" for="s-sub" :error="sendForm.errors.subject"><Input id="s-sub" v-model="sendForm.subject" /></FormField>
                <FormField label="Mensaje" for="s-msg" :error="sendForm.errors.message"><Textarea id="s-msg" v-model="sendForm.message" rows="4" /></FormField>
                <DialogFooter class="gap-2"><Button type="button" variant="ghost" @click="sendOpen = false">Cancelar</Button><Button type="submit" :disabled="sendForm.processing"><Spinner v-if="sendForm.processing" /><Send v-else /> Enviar</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog v-model:open="del" title="¿Eliminar esta propuesta?" description="Dejará de estar disponible el enlace público." confirm-label="Eliminar" @confirm="remove" />
</template>
