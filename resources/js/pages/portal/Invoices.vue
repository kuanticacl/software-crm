<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BankAccountsCard from '@/components/portal/BankAccountsCard.vue';
import type { PortalBank } from '@/components/portal/BankAccountsCard.vue';
import InvoiceCard from '@/components/portal/InvoiceCard.vue';
import type { PortalInvoice } from '@/components/portal/InvoiceCard.vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ company: { id: number; name: string }; invoices: PortalInvoice[]; bank: PortalBank }>();

const tab = ref<'pending' | 'paid' | 'all'>('pending');
const pendingCount = computed(() => props.invoices.filter((i) => i.status !== 'paid').length);
const list = computed(() => props.invoices.filter((i) => (tab.value === 'all' ? true : tab.value === 'paid' ? i.status === 'paid' : i.status !== 'paid')));
const tabs = computed(() => [
    { k: 'pending' as const, l: 'Por pagar', n: pendingCount.value },
    { k: 'paid' as const, l: 'Pagadas', n: props.invoices.length - pendingCount.value },
    { k: 'all' as const, l: 'Todas', n: props.invoices.length },
]);
</script>

<template>
    <Head title="Cobros" />

    <div class="flex flex-col gap-5">
        <div><h1 class="text-2xl font-semibold tracking-tight">Cobros</h1><p class="mt-1 text-sm text-muted-foreground">Boletas y cobros de {{ company.name }}: por pagar y pagados. Descarga el documento de cada uno.</p></div>

        <div class="flex gap-1 overflow-x-auto rounded-2xl border bg-card p-1.5">
            <button v-for="t in tabs" :key="t.k" type="button" :class="cn('inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors', tab === t.k ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted')" @click="tab = t.k">
                {{ t.l }} <span class="rounded-full bg-muted px-1.5 text-[11px]">{{ t.n }}</span>
            </button>
        </div>

        <p v-if="!list.length" class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground">{{ tab === 'pending' ? 'No tienes cobros por pagar. ¡Estás al día!' : 'No hay cobros para mostrar.' }}</p>
        <div class="grid gap-3"><InvoiceCard v-for="i in list" :key="i.id" :invoice="i" /></div>

        <BankAccountsCard v-if="tab !== 'paid'" :bank="bank" />
    </div>
</template>
