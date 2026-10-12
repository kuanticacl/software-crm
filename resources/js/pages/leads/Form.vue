<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CustomFieldInput from '@/components/CustomFieldInput.vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { index, show, store, update } from '@/routes/leads';
import type { CustomFieldDef, UserOption } from '@/types';

type LeadData = {
    id: number;
    first_name: string;
    last_name: string | null;
    email: string | null;
    phone: string | null;
    job_title: string | null;
    company: string | null;
    message: string | null;
    client_id: number | null;
    source_id: number;
    stage_id: number;
    assigned_to: number | null;
    custom: Record<string, unknown>;
};

const props = defineProps<{
    lead: LeadData | null;
    defaults: { source_id: number; stage_id: number; assigned_to: number; client_id: number | null } | null;
    sources: { id: number; name: string }[];
    stages: { id: number; name: string }[];
    users: UserOption[];
    clients: { id: number; name: string }[];
    fields: CustomFieldDef[];
    can: { assign: boolean; move: boolean };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Prospectos', href: index() }] } });

const isEdit = computed(() => !!props.lead);
const l = props.lead;
const d = props.defaults;

const custom: LeadForm['custom'] = {};
for (const f of props.fields) {
    custom[f.key] = (l?.custom?.[f.key] as LeadForm['custom'][string]) ?? (f.type === 'checkbox' ? false : null);
}

type LeadForm = {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    job_title: string;
    company: string;
    message: string;
    client_id: number | string;
    source_id: number | string;
    stage_id: number | string;
    assigned_to: number | string;
    custom: Record<string, string | number | boolean | null>;
};

const form = useForm<LeadForm>({
    first_name: l?.first_name ?? '',
    last_name: l?.last_name ?? '',
    email: l?.email ?? '',
    phone: l?.phone ?? '',
    job_title: l?.job_title ?? '',
    company: l?.company ?? '',
    message: l?.message ?? '',
    client_id: l?.client_id ?? d?.client_id ?? '',
    source_id: l?.source_id ?? d?.source_id ?? props.sources[0]?.id ?? '',
    stage_id: l?.stage_id ?? d?.stage_id ?? '',
    assigned_to: l?.assigned_to ?? d?.assigned_to ?? '',
    custom,
});

const submit = () => {
    props.lead ? form.submit(update(props.lead.id)) : form.submit(store());
};
const customError = (key: string) => (form.errors as Record<string, string>)[`custom.${key}`];
</script>

<template>
    <Head :title="isEdit ? 'Editar prospecto' : 'Nuevo prospecto'" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="isEdit ? `Editar a ${lead?.first_name} ${lead?.last_name ?? ''}` : 'Nuevo prospecto'" description="Datos de contacto, gestión comercial y campos personalizados." />

        <form class="flex max-w-4xl flex-col gap-5" @submit.prevent="submit">
            <DataCard class="p-6">
                <h2 class="mb-4 font-semibold">Contacto</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="Nombre" for="first_name" :error="form.errors.first_name" required><Input id="first_name" v-model="form.first_name" /></FormField>
                    <FormField label="Apellido" for="last_name" :error="form.errors.last_name"><Input id="last_name" v-model="form.last_name" /></FormField>
                    <FormField label="Correo" for="email" :error="form.errors.email"><Input id="email" v-model="form.email" type="email" /></FormField>
                    <FormField label="Teléfono" for="phone" :error="form.errors.phone"><Input id="phone" v-model="form.phone" placeholder="+56 9 1234 5678" /></FormField>
                    <FormField label="Cargo" for="job_title" :error="form.errors.job_title"><Input id="job_title" v-model="form.job_title" /></FormField>
                    <FormField label="Cliente" for="company" :error="form.errors.company"><Input id="company" v-model="form.company" /></FormField>
                    <FormField label="Mensaje o requerimiento" for="message" :error="form.errors.message" class="sm:col-span-2">
                        <Textarea id="message" v-model="form.message" rows="3" />
                    </FormField>
                </div>
            </DataCard>

            <DataCard class="p-6">
                <h2 class="mb-4 font-semibold">Gestión comercial</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="Origen" for="source_id" :error="form.errors.source_id" required>
                        <NativeSelect id="source_id" v-model="form.source_id">
                            <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </NativeSelect>
                    </FormField>
                    <FormField v-if="can.move" label="Etapa" for="stage_id" :error="form.errors.stage_id">
                        <NativeSelect id="stage_id" v-model="form.stage_id">
                            <option v-if="!isEdit" value="">Primera etapa (por defecto)</option>
                            <option v-for="s in stages" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </NativeSelect>
                    </FormField>
                    <FormField v-if="can.assign" label="Responsable" for="assigned_to" :error="form.errors.assigned_to">
                        <NativeSelect id="assigned_to" v-model="form.assigned_to">
                            <option value="">Sin asignar</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </NativeSelect>
                    </FormField>
                    <FormField label="Cliente" for="client_id" :error="form.errors.client_id" hint="Opcional: vincula el prospecto a un cliente de tu base.">
                        <NativeSelect id="client_id" v-model="form.client_id">
                            <option value="">Sin cliente</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </NativeSelect>
                    </FormField>
                </div>
            </DataCard>

            <DataCard v-if="fields.length" class="p-6">
                <h2 class="mb-4 font-semibold">Campos personalizados</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField v-for="f in fields" :key="f.key" :label="f.label" :for="`cf-${f.key}`" :error="customError(f.key)" :required="f.is_required"
                        :class="f.type === 'textarea' ? 'sm:col-span-2' : ''">
                        <CustomFieldInput :id="`cf-${f.key}`" v-model="form.custom[f.key]" :field="f" />
                    </FormField>
                </div>
            </DataCard>

            <div class="flex items-center justify-end gap-2">
                <Button variant="outline" as-child><Link :href="lead ? show(lead.id) : index()">Cancelar</Link></Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" /> {{ isEdit ? 'Guardar cambios' : 'Crear prospecto' }}
                </Button>
            </div>
        </form>
    </div>
</template>
