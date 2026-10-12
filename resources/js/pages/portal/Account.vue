<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, ShieldAlert, UserRound } from '@lucide/vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    company: { id: number; name: string; legal_name: string | null; tax_id: string | null; activity: string | null; email: string | null; phone: string | null; website: string | null; address: string; contact_name: string | null; contact_role: string | null };
    me: { name: string; email: string; phone: string | null; job_title: string | null };
    must_change_password: boolean;
    preview?: boolean;
}>();

const profile = useForm({ name: props.me.name, phone: props.me.phone ?? '' });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
const savePassword = () => props.preview ? undefined : password.put('/portal/cuenta/password', { preserveScroll: true, onSuccess: () => password.reset() });

const companyRows = [
    ['Razón social', props.company.legal_name], ['RUT', props.company.tax_id], ['Giro', props.company.activity], ['Contacto', [props.company.contact_name, props.company.contact_role].filter(Boolean).join(' · ')],
    ['Correo', props.company.email], ['Teléfono', props.company.phone], ['Sitio web', props.company.website], ['Dirección', props.company.address],
].filter(([, v]) => v);
</script>

<template>
    <Head title="Mi cuenta" />

    <div class="flex flex-col gap-5">
        <div><h1 class="text-2xl font-semibold tracking-tight">Mi cuenta</h1><p class="mt-1 text-sm text-muted-foreground">Tus datos y los de tu negocio. Si algo del cliente está desactualizado, escríbenos y lo corregimos.</p></div>

        <div v-if="must_change_password" class="flex items-start gap-3 rounded-2xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-300">
            <ShieldAlert class="mt-0.5 size-5 shrink-0" /><p><strong>Cambia tu contraseña.</strong> Ingresaste con una contraseña temporal; define una propia para proteger tu cuenta.</p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                <h2 class="mb-4 flex items-center gap-2 font-semibold"><UserRound class="size-4" /> Mis datos</h2>
                <form class="grid gap-4" @submit.prevent="!preview && profile.put('/portal/cuenta', { preserveScroll: true })">
                    <FormField label="Nombre" for="p-name" :error="profile.errors.name"><Input id="p-name" v-model="profile.name" /></FormField>
                    <FormField label="Correo de acceso" for="p-mail" hint="Para cambiarlo, escríbenos."><Input id="p-mail" :model-value="me.email" disabled /></FormField>
                    <FormField label="Teléfono" for="p-phone" :error="profile.errors.phone"><Input id="p-phone" v-model="profile.phone" /></FormField>
                    <div><Button type="submit" :disabled="profile.processing || preview"><Spinner v-if="profile.processing" /> Guardar</Button></div>
                </form>
            </section>

            <section class="rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03]">
                <h2 class="mb-4 flex items-center gap-2 font-semibold"><Building2 class="size-4" /> Mis datos</h2>
                <p class="mb-3 text-lg font-semibold">{{ company.name }}</p>
                <dl class="grid gap-3 text-sm"><div v-for="[k, v] in companyRows" :key="k as string"><dt class="text-xs text-muted-foreground">{{ k }}</dt><dd>{{ v }}</dd></div></dl>
            </section>

            <section class="rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03] lg:col-span-2">
                <h2 class="mb-4 font-semibold">Cambiar contraseña</h2>
                <form class="grid max-w-md gap-4" @submit.prevent="savePassword">
                    <FormField label="Contraseña actual" for="pw-cur" :error="password.errors.current_password"><Input id="pw-cur" v-model="password.current_password" type="password" autocomplete="current-password" /></FormField>
                    <FormField label="Nueva contraseña" for="pw-new" hint="Mínimo 10 caracteres, con letras y números." :error="password.errors.password"><Input id="pw-new" v-model="password.password" type="password" autocomplete="new-password" /></FormField>
                    <FormField label="Repite la nueva contraseña" for="pw-conf"><Input id="pw-conf" v-model="password.password_confirmation" type="password" autocomplete="new-password" /></FormField>
                    <div><Button type="submit" :disabled="password.processing || preview"><Spinner v-if="password.processing" /> Actualizar contraseña</Button></div>
                </form>
            </section>
        </div>
    </div>
</template>
