<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Building,
    ImagePlus,
    Percent,
    Save,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { clp } from '@/lib/realEstate';

type ProjectData = Record<string, unknown> & {
    id: number;
    name: string;
    cover: string | null;
    executive_ids: number[];
    lead_ids: number[];
};
const props = defineProps<{
    project: ProjectData | null;
    users: { id: number; name: string; email: string }[];
    stages: Record<string, string>;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Proyectos', href: '/projects' }] },
});

const p = props.project;
const form = useForm({
    name: (p?.name as string) ?? '',
    developer: (p?.developer as string) ?? '',
    commune: (p?.commune as string) ?? '',
    city: (p?.city as string) ?? '',
    address: (p?.address as string) ?? '',
    sales_office: (p?.sales_office as string) ?? '',
    description: (p?.description as string) ?? '',
    stage: (p?.stage as string) ?? 'en_blanco',
    delivery_date: (p?.delivery_date as string) ?? '',
    delivery_note: (p?.delivery_note as string) ?? '',
    website_url: (p?.website_url as string) ?? '',
    brochure_url: (p?.brochure_url as string) ?? '',
    color: (p?.color as string) ?? '#6419DB',
    reservation_amount: Number(p?.reservation_amount ?? 300000),
    default_pie_pct: Number(p?.default_pie_pct ?? 20),
    default_pie_installments: Number(p?.default_pie_installments ?? 24),
    max_discount_pct: Number(p?.max_discount_pct ?? 5),
    mortgage_rate: Number(p?.mortgage_rate ?? 4.5),
    mortgage_years: Number(p?.mortgage_years ?? 25),
    quote_validity_days: Number(p?.quote_validity_days ?? 7),
    promise_days: Number(p?.promise_days ?? 30),
    quote_conditions: (p?.quote_conditions as string) ?? '',
    is_active: (p?.is_active as boolean) ?? true,
    executive_ids: [...(p?.executive_ids ?? [])] as number[],
    lead_ids: [...(p?.lead_ids ?? [])] as number[],
    cover: null as File | null,
    remove_cover: false,
});

const preview = ref<string | null>(p?.cover ?? null);
const onCover = (e: Event) => {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.cover = f;
    form.remove_cover = false;
    preview.value = f ? URL.createObjectURL(f) : (p?.cover ?? null);
};
const removeCover = () => {
    form.cover = null;
    form.remove_cover = true;
    preview.value = null;
};

const toggleExec = (id: number) => {
    const i = form.executive_ids.indexOf(id);
    if (i >= 0) {
        form.executive_ids.splice(i, 1);
        form.lead_ids = form.lead_ids.filter((x) => x !== id);
    } else form.executive_ids.push(id);
};
const toggleLead = (id: number) =>
    (form.lead_ids = form.lead_ids.includes(id)
        ? form.lead_ids.filter((x) => x !== id)
        : [...form.lead_ids, id]);

const submit = () =>
    form
        .transform((d) => ({
            ...d,
            is_active: d.is_active ? 1 : 0,
            remove_cover: d.remove_cover ? 1 : 0,
        }))
        .post(p ? `/projects/${p.id}` : '/projects', {
            forceFormData: true,
            preserveScroll: true,
        });
const reservationHint = computed(() => clp(form.reservation_amount));
</script>

<template>
    <Head :title="p ? `Editar ${p.name}` : 'Nuevo proyecto'" />

    <form class="flex flex-col gap-6 p-4 md:p-6" @submit.prevent="submit">
        <PageHeader
            :title="p ? `Editar ${p.name}` : 'Nuevo proyecto'"
            description="Datos del proyecto, ejecutivos y condiciones comerciales por defecto de sus cotizaciones."
        >
            <template #actions>
                <Button variant="outline" as-child
                    ><Link :href="p ? `/projects/${p.id}` : '/projects'"
                        ><ArrowLeft /> Volver</Link
                    ></Button
                >
                <Button type="submit" :disabled="form.processing"
                    ><Spinner v-if="form.processing" /><Save v-else />
                    Guardar</Button
                >
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
            <div class="grid content-start gap-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-4 flex items-center gap-2 font-semibold">
                        <Building class="size-4 text-primary" /> Proyecto
                    </h2>
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <FormField
                            label="Nombre del proyecto"
                            for="p-name"
                            :error="form.errors.name"
                            required
                            class="sm:col-span-2"
                            ><Input
                                id="p-name"
                                v-model="form.name"
                                placeholder="Edificio Mirador Ñuñoa"
                        /></FormField>
                        <FormField
                            label="Inmobiliaria / desarrolladora"
                            for="p-dev"
                            :error="form.errors.developer"
                            ><Input id="p-dev" v-model="form.developer"
                        /></FormField>
                        <FormField
                            label="Etapa"
                            for="p-stage"
                            :error="form.errors.stage"
                            ><NativeSelect id="p-stage" v-model="form.stage"
                                ><option
                                    v-for="(l, k) in stages"
                                    :key="k"
                                    :value="k"
                                >
                                    {{ l }}
                                </option></NativeSelect
                            ></FormField
                        >
                        <FormField
                            label="Comuna"
                            for="p-com"
                            :error="form.errors.commune"
                            ><Input id="p-com" v-model="form.commune"
                        /></FormField>
                        <FormField
                            label="Ciudad"
                            for="p-city"
                            :error="form.errors.city"
                            ><Input
                                id="p-city"
                                v-model="form.city"
                                placeholder="Santiago"
                        /></FormField>
                        <FormField
                            label="Dirección del proyecto"
                            for="p-addr"
                            :error="form.errors.address"
                            class="sm:col-span-2"
                            ><Input id="p-addr" v-model="form.address"
                        /></FormField>
                        <FormField
                            label="Sala de ventas"
                            for="p-office"
                            :error="form.errors.sales_office"
                            class="sm:col-span-2"
                            hint="Dirección y horario; aparece en la cotización."
                            ><Input id="p-office" v-model="form.sales_office"
                        /></FormField>
                        <FormField
                            label="Fecha estimada de entrega"
                            for="p-deliv"
                            :error="form.errors.delivery_date"
                            ><Input
                                id="p-deliv"
                                v-model="form.delivery_date"
                                type="date"
                        /></FormField>
                        <FormField
                            label="Texto de entrega"
                            for="p-dnote"
                            :error="form.errors.delivery_note"
                            hint="Ej.: 2° semestre 2027"
                            ><Input id="p-dnote" v-model="form.delivery_note"
                        /></FormField>
                        <FormField
                            label="Sitio web del proyecto"
                            for="p-web"
                            :error="form.errors.website_url"
                            ><Input
                                id="p-web"
                                v-model="form.website_url"
                                placeholder="https://…"
                        /></FormField>
                        <FormField
                            label="Brochure (enlace)"
                            for="p-bro"
                            :error="form.errors.brochure_url"
                            ><Input
                                id="p-bro"
                                v-model="form.brochure_url"
                                placeholder="https://…"
                        /></FormField>
                        <FormField
                            label="Descripción"
                            for="p-desc"
                            :error="form.errors.description"
                            class="sm:col-span-2"
                            ><Textarea
                                id="p-desc"
                                v-model="form.description"
                                rows="3"
                                placeholder="Ubicación, conectividad, áreas comunes, terminaciones…"
                        /></FormField>
                    </div>
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-1 flex items-center gap-2 font-semibold">
                        <Percent class="size-4 text-primary" /> Condiciones
                        comerciales por defecto
                    </h2>
                    <p class="mb-4 text-xs text-muted-foreground">
                        Se proponen al cotizar; el ejecutivo puede ajustarlas en
                        cada cotización (el descuento, solo hasta el máximo).
                    </p>
                    <div
                        class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <FormField
                            label="Monto de reserva ($)"
                            for="c-res"
                            :error="form.errors.reservation_amount"
                            :hint="reservationHint"
                            ><Input
                                id="c-res"
                                v-model.number="form.reservation_amount"
                                type="number"
                                min="0"
                                step="10000"
                        /></FormField>
                        <FormField
                            label="Pie (%)"
                            for="c-pie"
                            :error="form.errors.default_pie_pct"
                            ><Input
                                id="c-pie"
                                v-model.number="form.default_pie_pct"
                                type="number"
                                min="0"
                                max="100"
                                step="0.5"
                        /></FormField>
                        <FormField
                            label="Cuotas del pie"
                            for="c-cuo"
                            :error="form.errors.default_pie_installments"
                            hint="0 = pie al contado"
                            ><Input
                                id="c-cuo"
                                v-model.number="form.default_pie_installments"
                                type="number"
                                min="0"
                                max="120"
                        /></FormField>
                        <FormField
                            label="Descuento máximo (%)"
                            for="c-dis"
                            :error="form.errors.max_discount_pct"
                            ><Input
                                id="c-dis"
                                v-model.number="form.max_discount_pct"
                                type="number"
                                min="0"
                                max="100"
                                step="0.5"
                        /></FormField>
                        <FormField
                            label="Tasa crédito (% anual)"
                            for="c-rate"
                            :error="form.errors.mortgage_rate"
                            ><Input
                                id="c-rate"
                                v-model.number="form.mortgage_rate"
                                type="number"
                                min="0"
                                max="30"
                                step="0.01"
                        /></FormField>
                        <FormField
                            label="Plazo crédito (años)"
                            for="c-yrs"
                            :error="form.errors.mortgage_years"
                            ><Input
                                id="c-yrs"
                                v-model.number="form.mortgage_years"
                                type="number"
                                min="1"
                                max="40"
                        /></FormField>
                        <FormField
                            label="Vigencia cotización (días)"
                            for="c-val"
                            :error="form.errors.quote_validity_days"
                            ><Input
                                id="c-val"
                                v-model.number="form.quote_validity_days"
                                type="number"
                                min="1"
                                max="365"
                        /></FormField>
                        <FormField
                            label="Plazo para promesa (días)"
                            for="c-prom"
                            :error="form.errors.promise_days"
                            ><Input
                                id="c-prom"
                                v-model.number="form.promise_days"
                                type="number"
                                min="1"
                                max="365"
                        /></FormField>
                        <FormField
                            label="Condiciones y notas legales de la cotización"
                            for="c-cond"
                            :error="form.errors.quote_conditions"
                            class="sm:col-span-2 lg:col-span-4"
                            ><Textarea
                                id="c-cond"
                                v-model="form.quote_conditions"
                                rows="3"
                                placeholder="Ej.: Valores sujetos a cambio sin previo aviso. La reserva se devuelve si el crédito no es aprobado…"
                        /></FormField>
                    </div>
                </section>
            </div>

            <div class="grid content-start gap-6">
                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-3 flex items-center gap-2 font-semibold">
                        <ImagePlus class="size-4 text-primary" /> Portada
                    </h2>
                    <div
                        class="relative flex h-44 items-center justify-center overflow-hidden rounded-xl border border-dashed bg-muted/40"
                        :style="
                            preview
                                ? {
                                      backgroundImage: `url('${preview}')`,
                                      backgroundSize: 'cover',
                                      backgroundPosition: 'center',
                                  }
                                : {}
                        "
                    >
                        <span
                            v-if="!preview"
                            class="text-sm text-muted-foreground"
                            >Foto o render del proyecto</span
                        >
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <label
                            class="inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-muted"
                            ><ImagePlus class="size-4" /> Elegir imagen<input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                class="hidden"
                                @change="onCover"
                        /></label>
                        <Button
                            v-if="preview"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="removeCover"
                            ><Trash2 /> Quitar</Button
                        >
                    </div>
                    <p
                        v-if="form.errors.cover"
                        class="mt-2 text-xs text-destructive"
                    >
                        {{ form.errors.cover }}
                    </p>
                    <label
                        class="mt-4 flex items-center justify-between gap-3 text-sm"
                        ><span
                            ><span class="font-medium">Proyecto activo</span
                            ><br /><span class="text-xs text-muted-foreground"
                                >Los inactivos no aparecen al cotizar.</span
                            ></span
                        ><Switch
                            :model-value="form.is_active"
                            @update:model-value="
                                (v: boolean) => (form.is_active = v)
                            "
                    /></label>
                </section>

                <section class="rounded-2xl border bg-card p-5">
                    <h2 class="mb-1 flex items-center gap-2 font-semibold">
                        <Users class="size-4 text-primary" /> Ejecutivos del
                        proyecto
                    </h2>
                    <p class="mb-3 text-xs text-muted-foreground">
                        Ven el proyecto, su inventario y cotizan sus unidades.
                        Marca la jefatura de ventas con ★.
                    </p>
                    <ul class="grid max-h-96 gap-1 overflow-y-auto">
                        <li
                            v-for="u in users"
                            :key="u.id"
                            class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-muted/50"
                        >
                            <input
                                :id="`u-${u.id}`"
                                type="checkbox"
                                class="size-4 accent-[var(--primary)]"
                                :checked="form.executive_ids.includes(u.id)"
                                @change="toggleExec(u.id)"
                            />
                            <label
                                :for="`u-${u.id}`"
                                class="min-w-0 flex-1 cursor-pointer"
                                ><span
                                    class="block truncate text-sm font-medium"
                                    >{{ u.name }}</span
                                ><span
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{ u.email }}</span
                                ></label
                            >
                            <button
                                v-if="form.executive_ids.includes(u.id)"
                                type="button"
                                class="rounded px-1.5 text-lg leading-none"
                                :class="
                                    form.lead_ids.includes(u.id)
                                        ? 'text-amber-500'
                                        : 'text-muted-foreground/40 hover:text-amber-500'
                                "
                                title="Jefatura de ventas"
                                @click="toggleLead(u.id)"
                            >
                                ★
                            </button>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </form>
</template>
