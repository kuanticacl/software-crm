<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, Globe, Receipt, Save, Share2 } from '@lucide/vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatRut } from '@/lib/rut';
import { edit, update } from '@/routes/agency';

const props = defineProps<{ agency: Record<string, string>; socials: Record<string, string>; holding: { name: string; url: string; logo: string }[]; taxDocuments: Record<string, string> }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Datos de la agencia', href: edit() }] } });

const form = useForm({ ...props.agency });
const ph: Record<string, string> = { instagram: 'https://www.instagram.com/…', linkedin: 'https://www.linkedin.com/company/…', facebook: 'https://www.facebook.com/…', youtube: 'https://www.youtube.com/@…', tiktok: 'https://www.tiktok.com/@…' };
</script>

<template>
    <Head title="Datos de la agencia" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Datos de la agencia" description="Aparecen en la firma y el pie de las propuestas comerciales (PDF y enlace público)." />

        <form class="grid max-w-4xl gap-5" @submit.prevent="form.put(update().url, { preserveScroll: true })">
            <section class="rounded-2xl border bg-card p-5">
                <h2 class="mb-4 flex items-center gap-2 font-semibold"><Building2 class="size-4 text-primary" /> Empresa</h2>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <FormField label="Razón social" for="a-legal" :error="form.errors.legal_name" required><Input id="a-legal" v-model="form.legal_name" /></FormField>
                    <FormField label="RUT" for="a-rut" :error="form.errors.tax_id"><Input id="a-rut" v-model="form.tax_id" @blur="form.tax_id = formatRut(form.tax_id)" /></FormField>
                    <FormField label="Dirección de la oficina" for="a-addr" :error="form.errors.address" required class="sm:col-span-2"><Input id="a-addr" v-model="form.address" /></FormField>
                    <FormField label="Correo de contacto" for="a-mail" :error="form.errors.email" required><Input id="a-mail" v-model="form.email" type="email" /></FormField>
                    <FormField label="Teléfono" for="a-tel" :error="form.errors.phone"><Input id="a-tel" v-model="form.phone" placeholder="+56 2 …" /></FormField>
                    <FormField label="Sitio web" for="a-web" :error="form.errors.website" required class="sm:col-span-2"><Input id="a-web" v-model="form.website" /></FormField>
                </div>
            </section>

            <section class="rounded-2xl border bg-card p-5">
                <h2 class="mb-1 flex items-center gap-2 font-semibold"><Receipt class="size-4 text-primary" /> Documento tributario</h2>
                <p class="mb-4 text-xs text-muted-foreground">Cómo se calculan los totales de las propuestas y qué documento se sugiere en cada cobro nuevo. Cada propuesta puede cambiarlo para un caso puntual.</p>
                <FormField label="Documento con que trabajas" for="a-taxdoc" :error="form.errors.tax_document" class="max-w-md">
                    <NativeSelect id="a-taxdoc" v-model="form.tax_document"><option v-for="(label, k) in taxDocuments" :key="k" :value="k">{{ label }}</option></NativeSelect>
                </FormField>
                <p class="mt-3 text-xs text-muted-foreground">
                    <template v-if="form.tax_document === 'iva'">Ej.: 1.000.000 neto + IVA 19% = 1.190.000.</template>
                    <template v-else-if="form.tax_document === 'honorarios'">Ej.: 847.500 neto + retención = boleta por 1.000.000 (recibes el neto completo).</template>
                    <template v-else-if="form.tax_document === 'exenta'">Ej.: 1.000.000 exento de IVA = 1.000.000.</template>
                    <template v-else>Los montos son finales, sin impuestos.</template>
                </p>
            </section>

            <section class="rounded-2xl border bg-card p-5">
                <h2 class="mb-1 flex items-center gap-2 font-semibold"><Share2 class="size-4 text-primary" /> Redes sociales</h2>
                <p class="mb-4 text-xs text-muted-foreground">Solo se muestran en las propuestas las redes que completes.</p>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <FormField v-for="(label, key) in socials" :key="key" :label="label" :for="`a-${key}`" :error="form.errors[key as string]"><Input :id="`a-${key}`" v-model="form[key as string]" :placeholder="ph[key as string]" /></FormField>
                </div>
            </section>

            <section v-if="holding.length" class="rounded-2xl border bg-card p-5">
                <h2 class="mb-1 flex items-center gap-2 font-semibold"><Globe class="size-4 text-primary" /> Empresas relacionadas</h2>
                <p class="mb-4 text-xs text-muted-foreground">Estas empresas aparecen en el pie de las propuestas y de los correos.</p>
                <div class="flex flex-wrap items-center gap-8">
                    <a v-for="h in holding" :key="h.name" :href="h.url" target="_blank" rel="noopener" class="opacity-90 transition hover:opacity-100"><img :src="`/brand/partners/${h.logo}.png`" :alt="h.name" class="h-10 w-auto"></a>
                </div>
            </section>

            <div><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /><Save v-else /> Guardar datos</Button></div>
        </form>
    </div>
</template>
