<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Landmark, Plus, Save, Trash2 } from '@lucide/vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { edit, update } from '@/routes/billing/settings';

type Account = { bank: string; account_type: string; number: string; holder: string; tax_id: string; email: string };
const props = defineProps<{
    settings: { issue_days_before: number; first_due_days: number; past_due_days: number; contract: string; accounts: Account[]; transfer_note: string };
    accountTypes: string[];
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Configuración de cobros', href: edit() }] } });

const form = useForm({
    issue_days_before: props.settings.issue_days_before, first_due_days: props.settings.first_due_days, past_due_days: props.settings.past_due_days,
    contract: props.settings.contract, accounts: props.settings.accounts.map((a) => ({ ...a })), transfer_note: props.settings.transfer_note,
});
const addAccount = () => form.accounts.push({ bank: '', account_type: props.accountTypes[0] ?? 'Cuenta corriente', number: '', holder: '', tax_id: '', email: '' });
const save = () => form.put(update().url, { preserveScroll: true });
</script>

<template>
    <Head title="Configuración de cobros" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Configuración de cobros" description="Plazos de la cobranza, cuentas para transferencias y el contrato de servicios que se muestra en las propuestas con plan.">
            <template #actions><Button :disabled="form.processing" @click="save"><Spinner v-if="form.processing" /><Save v-else /> Guardar</Button></template>
        </PageHeader>

        <section class="grid gap-4 rounded-2xl border bg-card p-5">
            <h2 class="text-sm font-semibold">Plazos</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <FormField label="Emitir el cobro de renovación (días antes del término)" for="a" :error="form.errors.issue_days_before" hint="Se emite este día y el vencimiento es la fecha de renovación."><Input id="a" v-model.number="form.issue_days_before" type="number" min="0" max="60" /></FormField>
                <FormField label="Plazo de pago del primer cobro (días)" for="b" :error="form.errors.first_due_days"><Input id="b" v-model.number="form.first_due_days" type="number" min="0" max="60" /></FormField>
                <FormField label="Pasa a «Con pago vencido» tras (días de atraso)" for="c" :error="form.errors.past_due_days" hint="No bloquea el acceso: la API informa el estado y tú decides suspender."><Input id="c" v-model.number="form.past_due_days" type="number" min="0" max="90" /></FormField>
            </div>
            <p class="text-xs text-muted-foreground">Los correos de aviso (al emitir, antes y después del vencimiento, pago recibido, renovación) se configuran en Email → Automatizaciones.</p>
        </section>

        <section class="grid gap-4 rounded-2xl border bg-card p-5">
            <div class="flex items-center justify-between"><h2 class="flex items-center gap-2 text-sm font-semibold"><Landmark class="size-4" /> Cuentas para transferencias</h2><Button type="button" variant="outline" size="sm" :disabled="form.accounts.length >= 6" @click="addAccount"><Plus /> Agregar cuenta</Button></div>
            <p v-if="!form.accounts.length" class="text-sm text-muted-foreground">Sin cuentas: los correos de cobro no incluirán datos de transferencia.</p>
            <div v-for="(a, i) in form.accounts" :key="i" class="grid gap-3 rounded-xl border p-4 sm:grid-cols-3">
                <FormField label="Banco" :for="`ab${i}`"><Input :id="`ab${i}`" v-model="a.bank" placeholder="Banco Estado" /></FormField>
                <FormField label="Tipo de cuenta" :for="`at${i}`"><NativeSelect :id="`at${i}`" v-model="a.account_type"><option v-for="t in accountTypes" :key="t" :value="t">{{ t }}</option></NativeSelect></FormField>
                <FormField label="N° de cuenta" :for="`an${i}`"><Input :id="`an${i}`" v-model="a.number" /></FormField>
                <FormField label="Titular" :for="`ah${i}`"><Input :id="`ah${i}`" v-model="a.holder" /></FormField>
                <FormField label="RUT" :for="`ar${i}`"><Input :id="`ar${i}`" v-model="a.tax_id" /></FormField>
                <FormField label="Correo para comprobantes" :for="`ae${i}`" :error="form.errors[`accounts.${i}.email` as 'accounts']"><div class="flex gap-2"><Input :id="`ae${i}`" v-model="a.email" type="email" /><Button type="button" variant="ghost" size="icon" class="text-destructive hover:text-destructive" title="Quitar" @click="form.accounts.splice(i, 1)"><Trash2 /></Button></div></FormField>
            </div>
            <FormField label="Nota para el comprobante" for="tn" hint="Aparece bajo las cuentas en los correos."><Textarea id="tn" v-model="form.transfer_note" rows="2" placeholder="Envía tu comprobante indicando el N° de cobro." /></FormField>
        </section>

        <section class="grid gap-3 rounded-2xl border bg-card p-5">
            <h2 class="text-sm font-semibold">Contrato de servicios</h2>
            <p class="text-xs text-muted-foreground">Se incluye al final de las propuestas con plan y queda guardado en cada suscripción como copia de lo que aceptó el cliente. Cambiarlo aquí no altera contratos ya firmados.</p>
            <RichTextEditor v-model="form.contract" />
            <p v-if="form.errors.contract" class="text-sm text-destructive">{{ form.errors.contract }}</p>
        </section>
    </div>
</template>
