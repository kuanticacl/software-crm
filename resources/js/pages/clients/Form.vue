<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatRut } from '@/lib/rut';
import { index, store, update } from '@/routes/clients';
import type { ClientRow } from '@/types';

const props = defineProps<{ client: ClientRow | null }>();
const isEdit = computed(() => !!props.client);
const isCompany = computed(() => form.kind === 'company');

defineOptions({ layout: { breadcrumbs: [{ title: 'Clientes', href: index() }] } });

const form = useForm({
    kind: props.client?.kind ?? 'person',
    name: props.client?.name ?? '',
    legal_name: props.client?.legal_name ?? '',
    activity: props.client?.activity ?? '',
    commune: props.client?.commune ?? '',
    contact_name: props.client?.contact_name ?? '',
    contact_role: props.client?.contact_role ?? '',
    tax_id: props.client?.tax_id ?? '',
    email: props.client?.email ?? '',
    phone: props.client?.phone ?? '',
    website: props.client?.website ?? '',
    address: props.client?.address ?? '',
    city: props.client?.city ?? '',
    notes: props.client?.notes ?? '',
    is_active: props.client?.is_active ?? true,
});

watch(() => form.tax_id, () => form.clearErrors('tax_id'));

const submit = () => {
    props.client ? form.submit(update(props.client.id)) : form.submit(store());
};
</script>

<template>
    <Head :title="isEdit ? 'Editar cliente' : 'Nuevo cliente'" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="isEdit ? `Editar ${client?.name}` : 'Nuevo cliente'" description="Un cliente puede ser una persona o una empresa." />

        <form class="max-w-3xl" @submit.prevent="submit">
            <DataCard class="p-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid grid-cols-2 gap-2 rounded-xl border p-1 sm:col-span-2" role="radiogroup" aria-label="Tipo de cliente">
                        <button v-for="k in [{ v: 'person', l: 'Persona' }, { v: 'company', l: 'Empresa' }]" :key="k.v" type="button" role="radio" :aria-checked="form.kind === k.v" :class="['rounded-lg px-4 py-2 text-sm font-medium transition-colors', form.kind === k.v ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted']" @click="form.kind = k.v as 'person' | 'company'">{{ k.l }}</button>
                    </div>
                    <p class="text-xs font-semibold tracking-wide text-muted-foreground uppercase sm:col-span-2">{{ isCompany ? 'Datos de la empresa' : 'Datos de la persona' }}</p>
                    <FormField label="RUT" for="tax_id" :error="form.errors.tax_id" hint="Se valida el dígito verificador y se formatea solo.">
                        <Input id="tax_id" v-model="form.tax_id" :placeholder="isCompany ? '76.123.456-7' : '12.345.678-5'" @blur="form.tax_id = formatRut(form.tax_id)" />
                    </FormField>
                    <FormField v-if="isCompany" label="Razón social" for="legal_name" :error="form.errors.legal_name">
                        <Input id="legal_name" v-model="form.legal_name" placeholder="Comercial Los Robles SpA" />
                    </FormField>
                    <FormField :label="isCompany ? 'Nombre de fantasía' : 'Nombre completo'" for="name" :error="form.errors.name" required :hint="isCompany ? 'Es el nombre con el que se muestra en el CRM y en las propuestas.' : 'Como aparecerá en propuestas y cobros.'">
                        <Input id="name" v-model="form.name" :placeholder="isCompany ? 'Los Robles' : 'María González Pérez'" />
                    </FormField>
                    <FormField v-if="isCompany" label="Giro" for="activity" :error="form.errors.activity">
                        <Input id="activity" v-model="form.activity" placeholder="Comercio minorista, servicios, construcción…" />
                    </FormField>
                    <FormField label="Dirección" for="address" :error="form.errors.address">
                        <Input id="address" v-model="form.address" placeholder="Av. Apoquindo 4500, of. 801" />
                    </FormField>
                    <div class="grid grid-cols-2 gap-3">
                        <FormField label="Comuna" for="commune" :error="form.errors.commune"><Input id="commune" v-model="form.commune" placeholder="Las Condes" /></FormField>
                        <FormField label="Ciudad" for="city" :error="form.errors.city"><Input id="city" v-model="form.city" placeholder="Santiago" /></FormField>
                    </div>
                    <FormField v-if="isCompany" label="Sitio web" for="website" :error="form.errors.website">
                        <Input id="website" v-model="form.website" placeholder="https://" />
                    </FormField>
                    <span v-if="isCompany" class="hidden sm:block" />

                    <p class="mt-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase sm:col-span-2">{{ isCompany ? 'Contacto principal' : 'Contacto' }}</p>
                    <FormField v-if="isCompany" label="Nombre del contacto" for="contact_name" :error="form.errors.contact_name"><Input id="contact_name" v-model="form.contact_name" /></FormField>
                    <FormField v-if="isCompany" label="Cargo" for="contact_role" :error="form.errors.contact_role"><Input id="contact_role" v-model="form.contact_role" placeholder="Gerente comercial" /></FormField>
                    <FormField label="Correo" for="email" :error="form.errors.email"><Input id="email" v-model="form.email" type="email" /></FormField>
                    <FormField label="Teléfono" for="phone" :error="form.errors.phone"><Input id="phone" v-model="form.phone" /></FormField>
                    <FormField label="Notas" for="notes" :error="form.errors.notes" class="sm:col-span-2">
                        <RichTextEditor v-model="form.notes" compact :min-height="90" placeholder="Notas internas sobre este cliente…" />
                    </FormField>
                    <label class="flex items-center gap-3 text-sm sm:col-span-2">
                        <Switch :model-value="form.is_active" @update:model-value="(v: boolean) => (form.is_active = v)" />
                        Cliente activo
                    </label>
                </div>
                <div class="mt-6 flex items-center justify-end gap-2 border-t pt-5">
                    <Button variant="outline" as-child><Link :href="index()">Cancelar</Link></Button>
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" /> {{ isEdit ? 'Guardar cambios' : 'Crear cliente' }}
                    </Button>
                </div>
            </DataCard>
        </form>
    </div>
</template>
