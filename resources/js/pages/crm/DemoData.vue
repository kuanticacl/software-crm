<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Eraser, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { index, purge } from '@/routes/demo-data';

type Counts = { leads: number; clients: number; proposals: number; emails: number; users: number };
defineProps<{ demo: Counts; all: Counts }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Datos de prueba', href: index() }] } });

const mode = ref<'demo' | 'all' | null>(null);
const confirm = ref('');
const processing = ref(false);
const run = () => {
    if (!mode.value) return;
    processing.value = true;
    router.post(purge().url, { mode: mode.value, confirm: confirm.value }, {
        preserveScroll: true,
        onSuccess: () => (mode.value = null),
        onFinish: () => { processing.value = false; confirm.value = ''; },
    });
};
const rows = (c: Counts) => [
    ['Prospectos (con sus notas y actividad)', c.leads],
    ['Clientes', c.clients],
    ['Propuestas', c.proposals],
    ['Correos del historial asociados', c.emails],
    ['Usuarios de demostración', c.users],
] as const;
</script>

<template>
    <Head title="Datos de prueba" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Datos de prueba" description="Deja el CRM limpio para empezar a usarlo en real. Es un borrado definitivo (no va a la papelera)." />

        <div class="flex items-start gap-3 rounded-2xl border bg-card p-4 text-sm">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-green" />
            <p>
                <strong>No se toca la configuración:</strong> orígenes y API keys, etapas, campos, plantillas, audiencias, automatizaciones, servicios y tarifas,
                datos de mi empresa, Resend y proveedores de IA, usuarios reales y roles.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section v-for="o in [
                { key: 'demo' as const, title: 'Solo datos de muestra', text: 'Lo que crearon los datos de demostración (correos @ejemplo.cl y @correo.cl, prospectos «de demostración» y ejecutivos de ejemplo). Conserva todo lo que ingresó de verdad.', counts: demo, danger: false },
                { key: 'all' as const, title: 'Todos los prospectos, clientes y propuestas', text: 'Vacía por completo la operación (incluye lo que esté en la papelera). Úsalo si todo lo que hay hoy es de prueba.', counts: all, danger: true },
            ]" :key="o.key" class="flex flex-col gap-4 rounded-2xl border bg-card p-5">
                <div>
                    <h2 class="font-semibold">{{ o.title }}</h2>
                    <p class="text-sm text-muted-foreground">{{ o.text }}</p>
                </div>
                <dl class="grid gap-1.5 text-sm">
                    <div v-for="[label, n] in rows(o.counts)" :key="label" class="flex justify-between border-b border-dashed pb-1.5">
                        <dt class="text-muted-foreground">{{ label }}</dt><dd class="font-medium">{{ n }}</dd>
                    </div>
                </dl>
                <Button :variant="o.danger ? 'destructive' : 'default'" class="self-start" :disabled="!Object.values(o.counts).some((n) => n > 0)" @click="mode = o.key"><Eraser /> Limpiar</Button>
            </section>
        </div>
    </div>

    <Dialog :open="!!mode" @update:open="(v: boolean) => !v && (mode = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ mode === 'all' ? 'Borrar todos los prospectos, clientes y propuestas' : 'Borrar los datos de muestra' }}</DialogTitle>
                <DialogDescription>Esta acción es definitiva y no se puede deshacer. Escribe <strong>LIMPIAR</strong> para confirmar.</DialogDescription>
            </DialogHeader>
            <Input v-model="confirm" placeholder="LIMPIAR" autocomplete="off" />
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="mode = null">Cancelar</Button>
                <Button variant="destructive" :disabled="confirm !== 'LIMPIAR' || processing" @click="run">Borrar definitivamente</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
