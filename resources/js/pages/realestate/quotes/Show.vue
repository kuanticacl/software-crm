<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Copy,
    CopyPlus,
    ExternalLink,
    Eye,
    FileDown,
    KeyRound,
    Mail,
    MessageCircle,
    Pencil,
    RotateCcw,
    Send,
    Trash2,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { clp, pct, toClp, uf as fmtUf } from '@/lib/realEstate';

type Item = {
    id: number;
    unit_id: number | null;
    label: string;
    detail: string | null;
    list_price_uf: number;
    discount_pct: number;
    price_uf: number;
    unit_status: string | null;
    floor_plan: string | null;
};
type Quote = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    status_color: string;
    project: { id: number; name: string; color: string } | null;
    buyer_name: string | null;
    buyer: { name?: string; rut?: string; email?: string; phone?: string };
    main_label: string | null;
    total_uf: number;
    subtotal_uf: number;
    discount_uf: number;
    uf_value: number | null;
    total_clp: number | null;
    owner: string | null;
    valid_until: string | null;
    created_at: string | null;
    pie_pct: number;
    pie_uf: number;
    reservation_amount: number;
    reservation_uf: number;
    pie_installments: number;
    installment_uf: number;
    credit_uf: number;
    mortgage_rate: number;
    mortgage_years: number;
    monthly_payment_uf: number;
    notes: string | null;
    internal_notes: string | null;
    view_count: number;
    lost_reason: string | null;
    public_url: string;
    sent_at: string | null;
    viewed_at: string | null;
    items: Item[];
    lead: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
    } | null;
    reservation: { id: number; number: string; status: string } | null;
};

const props = defineProps<{
    quote: Quote;
    unitStatuses: Record<string, string>;
    statuses: Record<string, string>;
    can: { edit: boolean; send: boolean; delete: boolean; reserve: boolean };
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Cotizaciones', href: '/quotes' },
            { title: 'Detalle', href: '#' },
        ],
    },
});

const q = computed(() => props.quote);
const ufv = computed(() => q.value.uf_value ?? 0);
const date = (d: string | null) =>
    d
        ? new Date(d.length === 10 ? `${d}T12:00:00` : d).toLocaleDateString(
              'es-CL',
          )
        : '—';
const unavailable = computed(() =>
    q.value.items.filter(
        (i) => i.unit_status && i.unit_status !== 'disponible',
    ),
);
const isOpen = computed(() => !['reserved', 'lost'].includes(q.value.status));

// ---------------------------------------------------------------- enviar y compartir
const recipient = computed(() => ({
    email: q.value.buyer.email ?? q.value.lead?.email ?? '',
    name: q.value.buyer.name ?? q.value.lead?.name ?? '',
}));
const sendOpen = ref(false);
const send = useForm({ to: '', name: '', message: '' });
const openSend = () => {
    const first = (recipient.value.name || '').split(' ')[0];
    send.to = recipient.value.email;
    send.name = recipient.value.name;
    send.message = `Hola${first ? ` ${first}` : ''}, te comparto la cotización de ${q.value.main_label ?? 'la unidad'} en ${q.value.project?.name ?? 'nuestro proyecto'}. Incluye el detalle del pie, la reserva y una simulación del dividendo. Quedo atento/a a tus dudas y a coordinar una visita a la sala de ventas.`;
    send.clearErrors();
    sendOpen.value = true;
};
const doSend = () =>
    send.post(`/quotes/${q.value.id}/send`, {
        preserveScroll: true,
        onSuccess: () => (sendOpen.value = false),
    });

const copyLink = async () => {
    try {
        await navigator.clipboard.writeText(q.value.public_url);
        toast.success('Enlace copiado.');
    } catch {
        toast.error('No se pudo copiar el enlace.');
    }
};
const whatsappUrl = computed(() => {
    const phone = (q.value.buyer.phone ?? q.value.lead?.phone ?? '').replace(
        /\D/g,
        '',
    );
    const text = `Hola${recipient.value.name ? ` ${recipient.value.name.split(' ')[0]}` : ''}, te comparto la cotización ${q.value.number} de ${q.value.project?.name ?? ''}: ${q.value.public_url}`;
    return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
});
const markShared = () =>
    router.post(`/quotes/${q.value.id}/shared`, {}, { preserveScroll: true });
const shareWhatsapp = () => {
    window.open(whatsappUrl.value, '_blank', 'noopener');
    markShared();
};

// ---------------------------------------------------------------- estado
const lostOpen = ref(false);
const lost = useForm({ status: 'lost', lost_reason: '' });
const doLost = () =>
    lost.put(`/quotes/${q.value.id}/status`, {
        preserveScroll: true,
        onSuccess: () => (lostOpen.value = false),
    });
const reopen = () =>
    router.put(
        `/quotes/${q.value.id}/status`,
        { status: 'sent' },
        { preserveScroll: true },
    );
const duplicate = () => router.post(`/quotes/${q.value.id}/duplicate`);
const deleteOpen = ref(false);
const deleting = ref(false);
const destroy = () =>
    router.delete(`/quotes/${q.value.id}`, {
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
    });

const planUrl = ref<string | null>(null);
</script>

<template>
    <Head :title="`Cotización ${quote.number}`" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <div
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Cotización {{ quote.number }}
                    </h1>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white"
                        :style="{ backgroundColor: quote.status_color }"
                        >{{ quote.status_label }}</span
                    >
                </div>
                <p
                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                >
                    <span v-if="quote.project" class="flex items-center gap-1.5"
                        ><span
                            class="size-2 rounded-full"
                            :style="{ backgroundColor: quote.project.color }"
                        /><Link
                            :href="`/projects/${quote.project.id}`"
                            class="hover:underline"
                            >{{ quote.project.name }}</Link
                        ></span
                    >
                    <span>Creada {{ date(quote.created_at) }}</span>
                    <span>Vigente hasta {{ date(quote.valid_until) }}</span>
                    <span v-if="quote.owner"
                        >Ejecutivo/a: {{ quote.owner }}</span
                    >
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button v-if="quote.reservation" as-child
                    ><Link :href="`/reservations/${quote.reservation.id}`"
                        ><KeyRound /> Ver reserva
                        {{ quote.reservation.number }}</Link
                    ></Button
                >
                <Button v-else-if="can.reserve && !unavailable.length" as-child
                    ><Link :href="`/reservations/create?quote=${quote.id}`"
                        ><KeyRound /> Reservar</Link
                    ></Button
                >
                <Button
                    v-if="can.send && isOpen"
                    variant="outline"
                    @click="openSend"
                    ><Mail /> Enviar por correo</Button
                >
                <Button variant="outline" as-child
                    ><a
                        :href="`/quotes/${quote.id}/pdf`"
                        target="_blank"
                        rel="noopener"
                        ><FileDown /> PDF</a
                    ></Button
                >
                <Button v-if="can.edit" variant="outline" as-child
                    ><Link :href="`/quotes/${quote.id}/edit`"
                        ><Pencil /> Editar</Link
                    ></Button
                >
            </div>
        </div>

        <div
            v-if="unavailable.length && !quote.reservation"
            class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200"
        >
            {{
                unavailable
                    .map(
                        (i) =>
                            `${i.label} (${unitStatuses[i.unit_status ?? ''] ?? i.unit_status})`,
                    )
                    .join(', ')
            }}
            ya no
            {{
                unavailable.length > 1
                    ? 'están disponibles'
                    : 'está disponible'
            }}: duplica la cotización con otras unidades para poder reservar.
        </div>
        <div
            v-if="quote.status === 'lost'"
            class="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm"
        >
            Cotización perdida{{
                quote.lost_reason ? `: ${quote.lost_reason}` : ''
            }}.
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_340px]">
            <div class="flex flex-col gap-6">
                <section class="overflow-hidden rounded-2xl border bg-card">
                    <header class="border-b px-4 py-3 font-semibold">
                        Unidades cotizadas
                    </header>
                    <div class="divide-y">
                        <div
                            v-for="i in quote.items"
                            :key="i.id"
                            class="flex flex-wrap items-center gap-3 px-4 py-3"
                        >
                            <button
                                v-if="i.floor_plan"
                                type="button"
                                class="size-14 shrink-0 overflow-hidden rounded-lg border bg-muted"
                                title="Ver planta"
                                @click="planUrl = i.floor_plan"
                            >
                                <img
                                    :src="i.floor_plan"
                                    alt="Planta"
                                    class="size-full object-cover"
                                />
                            </button>
                            <div class="min-w-40 flex-1">
                                <p class="font-medium">{{ i.label }}</p>
                                <p
                                    v-if="i.detail"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ i.detail }}
                                </p>
                            </div>
                            <div class="text-right text-sm">
                                <p
                                    v-if="i.discount_pct > 0"
                                    class="text-xs text-muted-foreground"
                                >
                                    <s class="tabular-nums">{{
                                        fmtUf(i.list_price_uf)
                                    }}</s>
                                    · −{{ pct(i.discount_pct) }}
                                </p>
                                <p class="font-semibold tabular-nums">
                                    {{ fmtUf(i.price_uf) }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        class="grid gap-1 border-t bg-muted/30 px-4 py-3 text-sm"
                    >
                        <div class="flex justify-between">
                            <span class="text-muted-foreground"
                                >Precio de lista</span
                            ><span class="tabular-nums">{{
                                fmtUf(quote.subtotal_uf)
                            }}</span>
                        </div>
                        <div
                            v-if="quote.discount_uf > 0"
                            class="flex justify-between"
                        >
                            <span class="text-muted-foreground">Descuento</span
                            ><span class="tabular-nums"
                                >−{{ fmtUf(quote.discount_uf) }}</span
                            >
                        </div>
                        <div
                            class="flex justify-between text-base font-semibold"
                        >
                            <span>Total</span
                            ><span class="tabular-nums">{{
                                fmtUf(quote.total_uf)
                            }}</span>
                        </div>
                        <div
                            v-if="ufv"
                            class="flex justify-between text-xs text-muted-foreground"
                        >
                            <span>Referencia en pesos (UF {{ clp(ufv) }})</span
                            ><span class="tabular-nums">{{
                                clp(toClp(quote.total_uf, ufv))
                            }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border bg-card p-4">
                    <h2 class="mb-3 font-semibold">Forma de pago</h2>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl bg-muted/40 p-3">
                            <p class="text-xs text-muted-foreground">
                                Pie ({{ pct(quote.pie_pct) }})
                            </p>
                            <p class="text-lg font-semibold tabular-nums">
                                {{ fmtUf(quote.pie_uf) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Reserva {{ clp(quote.reservation_amount)
                                }}<template v-if="quote.pie_installments > 0">
                                    + {{ quote.pie_installments }} cuotas de
                                    {{ fmtUf(quote.installment_uf) }}</template
                                ><template v-else> + saldo al contado</template>
                            </p>
                        </div>
                        <div class="rounded-xl bg-muted/40 p-3">
                            <p class="text-xs text-muted-foreground">
                                Crédito hipotecario
                            </p>
                            <p class="text-lg font-semibold tabular-nums">
                                {{ fmtUf(quote.credit_uf) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ pct(quote.mortgage_rate) }} anual ·
                                {{ quote.mortgage_years }} años
                            </p>
                        </div>
                        <div class="rounded-xl bg-primary/10 p-3">
                            <p class="text-xs text-muted-foreground">
                                Dividendo estimado
                            </p>
                            <p
                                class="text-lg font-semibold text-primary tabular-nums"
                            >
                                {{ fmtUf(quote.monthly_payment_uf) }}
                            </p>
                            <p
                                v-if="ufv"
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                ≈
                                {{ clp(toClp(quote.monthly_payment_uf, ufv)) }}
                                / mes, sin seguros
                            </p>
                        </div>
                    </div>
                </section>

                <section
                    v-if="quote.notes || quote.internal_notes"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div
                        v-if="quote.notes"
                        class="rounded-2xl border bg-card p-4"
                    >
                        <h2 class="mb-1 text-sm font-semibold">
                            Observaciones para el cliente
                        </h2>
                        <p
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ quote.notes }}
                        </p>
                    </div>
                    <div
                        v-if="quote.internal_notes"
                        class="rounded-2xl border border-dashed bg-card p-4"
                    >
                        <h2 class="mb-1 text-sm font-semibold">
                            Notas internas
                        </h2>
                        <p
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ quote.internal_notes }}
                        </p>
                    </div>
                </section>
            </div>

            <aside class="flex flex-col gap-4">
                <section class="rounded-2xl border bg-card p-4 text-sm">
                    <h2 class="mb-2 font-semibold">Comprador</h2>
                    <p class="font-medium">
                        {{ quote.buyer_name ?? 'Sin nombre' }}
                    </p>
                    <p v-if="quote.buyer.rut" class="text-muted-foreground">
                        RUT {{ quote.buyer.rut }}
                    </p>
                    <p v-if="quote.buyer.email" class="text-muted-foreground">
                        {{ quote.buyer.email }}
                    </p>
                    <p v-if="quote.buyer.phone" class="text-muted-foreground">
                        {{ quote.buyer.phone }}
                    </p>
                    <Link
                        v-if="quote.lead"
                        :href="`/leads/${quote.lead.id}`"
                        class="mt-2 inline-flex items-center gap-1 text-primary hover:underline"
                        >Ver prospecto <ExternalLink class="size-3.5"
                    /></Link>
                </section>

                <section class="rounded-2xl border bg-card p-4 text-sm">
                    <h2 class="mb-2 font-semibold">Seguimiento</h2>
                    <ul class="grid gap-1.5 text-muted-foreground">
                        <li class="flex justify-between">
                            <span>Enviada</span
                            ><span>{{ date(quote.sent_at) }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Primera vista</span
                            ><span>{{ date(quote.viewed_at) }}</span>
                        </li>
                        <li class="flex justify-between">
                            <span>Visitas al enlace</span
                            ><span class="flex items-center gap-1"
                                ><Eye class="size-3.5" />{{
                                    quote.view_count
                                }}</span
                            >
                        </li>
                    </ul>
                    <div class="mt-3 grid gap-2">
                        <Button variant="outline" size="sm" @click="copyLink"
                            ><Copy /> Copiar enlace público</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            @click="shareWhatsapp"
                            ><MessageCircle /> Compartir por WhatsApp</Button
                        >
                        <Button
                            v-if="quote.status === 'draft'"
                            variant="ghost"
                            size="sm"
                            @click="markShared"
                            ><Send /> Marcar como enviada</Button
                        >
                        <Button variant="ghost" size="sm" as-child
                            ><a
                                :href="quote.public_url"
                                target="_blank"
                                rel="noopener"
                                ><ExternalLink /> Ver como cliente</a
                            ></Button
                        >
                    </div>
                </section>

                <section class="grid gap-2 rounded-2xl border bg-card p-4">
                    <Button variant="outline" size="sm" @click="duplicate"
                        ><CopyPlus /> Duplicar</Button
                    >
                    <Button
                        v-if="isOpen && quote.status !== 'draft'"
                        variant="outline"
                        size="sm"
                        @click="lostOpen = true"
                        ><XCircle /> Marcar como perdida</Button
                    >
                    <Button
                        v-if="isOpen && quote.status === 'draft'"
                        variant="outline"
                        size="sm"
                        @click="lostOpen = true"
                        ><XCircle /> Descartar</Button
                    >
                    <Button
                        v-if="quote.status === 'lost'"
                        variant="outline"
                        size="sm"
                        @click="reopen"
                        ><RotateCcw /> Reabrir</Button
                    >
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        @click="deleteOpen = true"
                        ><Trash2 /> Eliminar</Button
                    >
                </section>
            </aside>
        </div>
    </div>

    <Dialog v-model:open="sendOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Enviar cotización</DialogTitle>
                <DialogDescription
                    >El cliente recibe un correo con el enlace a la cotización y
                    su PDF. La UF queda fija desde el envío.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="doSend">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Correo"
                        for="s-to"
                        :error="send.errors.to"
                        required
                        ><Input id="s-to" v-model="send.to" type="email"
                    /></FormField>
                    <FormField
                        label="Nombre"
                        for="s-name"
                        :error="send.errors.name"
                        ><Input id="s-name" v-model="send.name"
                    /></FormField>
                </div>
                <FormField
                    label="Mensaje"
                    for="s-msg"
                    :error="send.errors.message"
                    required
                    ><Textarea id="s-msg" v-model="send.message" rows="5"
                /></FormField>
                <DialogFooter
                    ><Button type="submit" :disabled="send.processing"
                        ><Spinner v-if="send.processing" /><Send v-else />
                        Enviar</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="lostOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader
                ><DialogTitle>Marcar como perdida</DialogTitle
                ><DialogDescription
                    >Registra el motivo para entender por qué no se
                    concretó.</DialogDescription
                ></DialogHeader
            >
            <form class="grid gap-4" @submit.prevent="doLost">
                <FormField
                    label="Motivo"
                    for="l-reason"
                    :error="lost.errors.lost_reason"
                    ><Input
                        id="l-reason"
                        v-model="lost.lost_reason"
                        placeholder="Precio, ubicación, no califica al crédito, compró en otro proyecto…"
                /></FormField>
                <DialogFooter
                    ><Button
                        type="submit"
                        variant="destructive"
                        :disabled="lost.processing"
                        ><Spinner v-if="lost.processing" /> Marcar como
                        perdida</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="!!planUrl"
        @update:open="(v: boolean) => !v && (planUrl = null)"
    >
        <DialogContent class="sm:max-w-4xl"
            ><DialogHeader><DialogTitle>Planta</DialogTitle></DialogHeader
            ><img
                v-if="planUrl"
                :src="planUrl"
                alt="Planta"
                class="max-h-[75dvh] w-full rounded-lg border object-contain"
        /></DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Eliminar cotización"
        :description="`Se eliminará la cotización ${quote.number}. Esta acción no se puede deshacer.`"
        confirm-label="Eliminar"
        :processing="deleting"
        @confirm="destroy"
    />
</template>
