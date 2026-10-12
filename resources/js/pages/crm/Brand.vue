<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ImagePlus, Moon, RotateCcw, Sun, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { contrast, currentColor, deriveTheme, ensureContrastWithWhite, isHex, type Picks } from '@/lib/brandTheme';
import { hero as heroRoute, index, reset as resetRoute, update } from '@/routes/brand';
import { remove as removeHero } from '@/routes/brand/hero';

const props = defineProps<{
    saved: { picks: Picks | null; radius: string | null; font_sans: string | null; font_title: string | null };
    customized: boolean;
    hero: string | null;
    options: { radii: string[]; sans: string[]; titles: string[] };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Identidad visual', href: index() }] } });

const picks = ref<Picks>(props.saved.picks ?? { primary: '#a9606b', background: '#fbf8f6', sidebar: '#f6efeb', text: '#3b2f2f' });
const radius = ref(props.saved.radius ?? '');
const fontSans = ref(props.saved.font_sans ?? '');
const fontTitle = ref(props.saved.font_title ?? '');
const darkPreview = ref(false);

// Sin personalización guardada, los selectores parten del diseño que está en uso.
onMounted(() => {
    if (props.saved.picks) return;
    picks.value = {
        primary: currentColor('--primary', picks.value.primary),
        background: currentColor('--background', picks.value.background),
        sidebar: currentColor('--sidebar-background', picks.value.sidebar),
        text: currentColor('--foreground', picks.value.text),
    };
});

const presets: { name: string; picks: Picks }[] = [
    { name: 'Oro rosa', picks: { primary: '#a9606b', background: '#fbf8f6', sidebar: '#f6efeb', text: '#3b2f2f' } },
    { name: 'Verde bosque', picks: { primary: '#2f7d5b', background: '#f6f8f5', sidebar: '#eaf0ea', text: '#1f2d27' } },
    { name: 'Azul marino', picks: { primary: '#1f4e8c', background: '#f4f6fa', sidebar: '#101a2e', text: '#18202f' } },
    { name: 'Naranja', picks: { primary: '#d9480f', background: '#f7f5f2', sidebar: '#ffffff', text: '#2a2522' } },
    { name: 'Grafito', picks: { primary: '#3d4852', background: '#f5f6f7', sidebar: '#e9ecef', text: '#1c2227' } },
];

const valid = computed(() => Object.values(picks.value).every(isHex));
const theme = computed(() => (valid.value ? deriveTheme(picks.value) : null));
const previewStyle = computed(() => {
    const t = theme.value;
    if (!t) return {};

    return { ...(darkPreview.value ? t.dark : t.light), ...(radius.value ? { '--radius': radius.value } : {}), ...(fontSans.value ? { fontFamily: `'${fontSans.value}', sans-serif` } : {}) } as Record<string, string>;
});
const buttonContrast = computed(() => (valid.value ? contrast(picks.value.primary, '#ffffff') : 0));
const fixContrast = () => (picks.value.primary = ensureContrastWithWhite(picks.value.primary));

const form = useForm({});
const save = () => {
    if (!theme.value) return;
    router.put(update().url, { picks: picks.value, light: theme.value.light, dark: theme.value.dark, radius: radius.value || null, font_sans: fontSans.value || null, font_title: fontTitle.value || null }, { preserveScroll: true });
};

const confirmReset = ref(false);
const doReset = () => router.post(resetRoute().url, {}, { preserveScroll: true, onFinish: () => (confirmReset.value = false) });

// ---- hero ----
const heroForm = useForm<{ image: File | null }>({ image: null });
const fileInput = ref<HTMLInputElement | null>(null);
const onFile = (e: Event) => {
    heroForm.image = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (heroForm.image) heroForm.post(heroRoute().url, { forceFormData: true, preserveScroll: true, onFinish: () => { heroForm.reset(); if (fileInput.value) fileInput.value.value = ''; } });
};
const dropHero = () => router.delete(removeHero().url, { preserveScroll: true });
</script>

<template>
    <Head title="Identidad visual" />

    <div class="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Identidad visual" description="Cambia los colores, la forma, las tipografías y la imagen hero del CRM, del portal y de las propuestas.">
            <template #actions>
                <Badge variant="secondary">{{ customized ? 'Personalizado' : 'Diseño original' }}</Badge>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[1fr_1.1fr]">
            <div class="grid content-start gap-6">
                <DataCard class="grid gap-5 p-6">
                    <div>
                        <h2 class="font-semibold">Colores</h2>
                        <p class="text-xs text-muted-foreground">Elige 4 colores; el resto (bordes, fondos suaves, estados y modo oscuro) se calcula solo.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="p in presets" :key="p.name" type="button" class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors hover:bg-accent" @click="picks = { ...p.picks }">
                            <span class="size-3.5 rounded-full border" :style="{ backgroundColor: p.picks.primary }" /> {{ p.name }}
                        </button>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField v-for="f in [{ k: 'primary', l: 'Color de acción (botones y enlaces)' }, { k: 'background', l: 'Fondo de las páginas' }, { k: 'sidebar', l: 'Barra lateral' }, { k: 'text', l: 'Texto principal' }] as const" :key="f.k" :label="f.l" :for="`c-${f.k}`" :error="isHex(picks[f.k]) ? undefined : 'Usa un color #RRGGBB'">
                            <div class="flex items-center gap-2">
                                <input :id="`c-${f.k}`" v-model="picks[f.k]" type="color" class="h-9 w-12 shrink-0 cursor-pointer rounded-md border bg-transparent p-1" :aria-label="f.l" />
                                <Input v-model="picks[f.k]" class="font-mono uppercase" maxlength="7" />
                            </div>
                        </FormField>
                    </div>
                    <p v-if="valid" :class="['flex flex-wrap items-center gap-2 rounded-xl p-3 text-xs', buttonContrast >= 4.5 ? 'bg-muted text-muted-foreground' : 'bg-amber-500/10 text-amber-800 dark:text-amber-300']">
                        Contraste del texto del botón: <strong>{{ buttonContrast.toFixed(1) }} : 1</strong>
                        <template v-if="buttonContrast < 4.5">(bajo: el texto del botón se verá más tenue)
                            <Button type="button" size="sm" variant="outline" @click="fixContrast">Oscurecer el color para que se lea bien</Button>
                        </template>
                        <template v-else>(cumple 4.5 : 1)</template>
                    </p>
                </DataCard>

                <DataCard class="grid gap-5 p-6">
                    <h2 class="font-semibold">Forma y tipografía</h2>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField label="Esquinas" for="radius">
                            <NativeSelect id="radius" v-model="radius"><option value="">Original</option><option v-for="r in options.radii" :key="r" :value="r">{{ { '0.25rem': 'Rectas', '0.5rem': 'Suaves', '0.75rem': 'Redondeadas', '1rem': 'Muy redondeadas' }[r] }}</option></NativeSelect>
                        </FormField>
                        <FormField label="Fuente de texto" for="fsans">
                            <NativeSelect id="fsans" v-model="fontSans"><option value="">Original</option><option v-for="f in options.sans" :key="f" :value="f">{{ f }}</option></NativeSelect>
                        </FormField>
                        <FormField label="Fuente de títulos" for="ftitle">
                            <NativeSelect id="ftitle" v-model="fontTitle"><option value="">Original</option><option v-for="f in options.titles" :key="f" :value="f">{{ f }}</option></NativeSelect>
                        </FormField>
                    </div>
                    <p class="text-xs text-muted-foreground">Las fuentes se cargan desde Bunny Fonts (sin rastreo). «Original» mantiene la tipografía de la marca.</p>
                </DataCard>

                <DataCard class="grid gap-4 p-6">
                    <div>
                        <h2 class="font-semibold">Imagen hero</h2>
                        <p class="text-xs text-muted-foreground">Se usa de fondo en el inicio de sesión y en la portada de las propuestas (web y PDF). JPG, PNG o WebP, ideal 1920 × 1080 px, hasta 6 MB.</p>
                    </div>
                    <div class="relative aspect-[16/7] overflow-hidden rounded-xl border bg-muted">
                        <img v-if="hero" :src="hero" alt="Imagen hero actual" class="size-full object-cover" />
                        <div v-else class="flex size-full items-center justify-center text-sm text-muted-foreground">Sin imagen personalizada: se usa la original</div>
                        <div v-if="heroForm.processing" class="absolute inset-0 flex items-center justify-center bg-background/70"><Spinner /></div>
                    </div>
                    <p v-if="heroForm.errors.image" class="text-sm text-destructive" role="alert">{{ heroForm.errors.image }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button type="button" :disabled="heroForm.processing" @click="fileInput?.click()"><ImagePlus /> {{ hero ? 'Cambiar imagen' : 'Subir imagen' }}</Button>
                        <Button v-if="hero" type="button" variant="outline" class="text-destructive" @click="dropHero"><Trash2 /> Quitar</Button>
                        <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFile" />
                    </div>
                </DataCard>
            </div>

            <div class="grid content-start gap-4 lg:sticky lg:top-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">Vista previa</h2>
                    <label class="flex items-center gap-2 text-xs text-muted-foreground"><Sun class="size-3.5" /><Switch :model-value="darkPreview" @update:model-value="(v: boolean) => (darkPreview = v)" /><Moon class="size-3.5" /></label>
                </div>
                <div :class="['overflow-hidden rounded-2xl border shadow-sm', darkPreview && 'dark']" :style="previewStyle">
                    <div class="flex min-h-[26rem] bg-background text-foreground">
                        <aside class="hidden w-40 shrink-0 flex-col gap-1 border-r p-3 text-sm sm:flex" style="background: var(--sidebar-background); color: var(--sidebar-foreground); border-color: var(--sidebar-border)">
                            <p class="mb-2 px-2 text-xs font-semibold tracking-wide uppercase opacity-60">Menú</p>
                            <span class="rounded-md px-2 py-1.5 font-medium" style="background: var(--sidebar-accent); color: var(--sidebar-accent-foreground)">Dashboard</span>
                            <span class="px-2 py-1.5">Clientes</span>
                            <span class="px-2 py-1.5">Propuestas</span>
                            <span class="px-2 py-1.5">Cobros</span>
                        </aside>
                        <div class="grid flex-1 content-start gap-4 p-5">
                            <h3 class="text-2xl font-semibold" :style="fontTitle ? { fontFamily: `'${fontTitle}', serif` } : {}">Título de ejemplo</h3>
                            <p class="text-sm text-muted-foreground">Así se verán los textos secundarios, los botones y los formularios.</p>
                            <div class="flex flex-wrap gap-2">
                                <Button size="sm">Botón principal</Button>
                                <Button size="sm" variant="outline">Contorno</Button>
                                <Button size="sm" variant="secondary">Secundario</Button>
                                <Button size="sm" variant="ghost">Fantasma</Button>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2"><Input placeholder="Campo de texto" /><NativeSelect><option>Selector</option></NativeSelect></div>
                            <div class="flex flex-wrap items-center gap-2"><Badge>Etiqueta</Badge><Badge variant="secondary">Neutra</Badge><span class="text-sm font-medium text-primary underline-offset-4 hover:underline">Enlace de ejemplo</span></div>
                            <div class="rounded-xl border bg-card p-4 text-card-foreground shadow-sm"><p class="text-sm font-semibold">Tarjeta</p><p class="mt-1 text-xs text-muted-foreground">Contenido de ejemplo con borde y fondo de tarjeta.</p></div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button v-if="customized" type="button" variant="outline" @click="confirmReset = true"><RotateCcw /> Volver al diseño original</Button>
                    <Button type="button" :disabled="!valid || form.processing" @click="save">Guardar identidad visual</Button>
                </div>
            </div>
        </div>
    </div>

    <ConfirmDialog v-model:open="confirmReset" title="¿Volver al diseño original?" description="Se quitan los colores, la forma y las tipografías personalizados (la imagen hero se conserva)." confirm-label="Restablecer" @confirm="doReset" />
</template>
