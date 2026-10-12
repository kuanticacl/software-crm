<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatRut } from '@/lib/rut';

type Account = { bank: string; account_type: string; number: string; holder: string; tax_id: string; email: string };

const props = defineProps<{ accounts: Account[]; note: string; types: string[] }>();
const open = defineModel<boolean>('open', { default: false });

const blank = (): Account => ({ bank: '', account_type: props.types[0] ?? 'Cuenta corriente', number: '', holder: '', tax_id: '', email: '' });
const form = useForm({ accounts: props.accounts.length ? props.accounts.map((a) => ({ ...a })) : [blank()], note: props.note });

const err = (i: number, f: string) => (form.errors as Record<string, string>)[`accounts.${i}.${f}`];
const save = () => form.put('/billing/bank-accounts', { preserveScroll: true, onSuccess: () => (open.value = false) });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Datos bancarios para transferencias</DialogTitle>
                <DialogDescription>Se muestran a tus clientes en el portal (con botón para copiar) y en los correos de cobro y recordatorio. Puedes cargar hasta 5 cuentas.</DialogDescription>
            </DialogHeader>

            <form class="grid gap-5" @submit.prevent="save">
                <div v-for="(a, i) in form.accounts" :key="i" class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2">
                    <div class="flex items-center justify-between sm:col-span-2">
                        <p class="text-sm font-semibold">Cuenta {{ i + 1 }}</p>
                        <Button v-if="form.accounts.length > 1" type="button" variant="ghost" size="icon-sm" class="text-destructive" title="Quitar cuenta" @click="form.accounts.splice(i, 1)"><Trash2 /></Button>
                    </div>
                    <FormField label="Banco" :for="`b-${i}`" required :error="err(i, 'bank')"><Input :id="`b-${i}`" v-model="a.bank" placeholder="Banco de Chile" /></FormField>
                    <FormField label="Tipo de cuenta" :for="`t-${i}`" required :error="err(i, 'account_type')"><NativeSelect :id="`t-${i}`" v-model="a.account_type"><option v-for="t in types" :key="t">{{ t }}</option></NativeSelect></FormField>
                    <FormField label="N° de cuenta" :for="`n-${i}`" required :error="err(i, 'number')"><Input :id="`n-${i}`" v-model="a.number" inputmode="numeric" placeholder="00-123-45678-90" /></FormField>
                    <FormField label="Titular" :for="`h-${i}`" :error="err(i, 'holder')"><Input :id="`h-${i}`" v-model="a.holder" placeholder="Razón social de tu negocio" /></FormField>
                    <FormField label="RUT del titular" :for="`r-${i}`" :error="err(i, 'tax_id')"><Input :id="`r-${i}`" v-model="a.tax_id" placeholder="76.123.456-7" @blur="a.tax_id = formatRut(a.tax_id)" /></FormField>
                    <FormField label="Correo para el comprobante" :for="`e-${i}`" hint="Donde tus clientes avisan que transfirieron." :error="err(i, 'email')"><Input :id="`e-${i}`" v-model="a.email" type="email" placeholder="cobranza@viviendaenregla.cl" /></FormField>
                </div>

                <div><Button v-if="form.accounts.length < 5" type="button" variant="outline" size="sm" @click="form.accounts.push(blank())"><Plus /> Agregar otra cuenta</Button></div>

                <FormField label="Nota para el cliente" for="bank-note" hint="Opcional. Ej.: «Indica el N° de factura en el comentario de la transferencia»." :error="form.errors.note"><Textarea id="bank-note" v-model="form.note" rows="2" /></FormField>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="outline" @click="open = false">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Guardar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
