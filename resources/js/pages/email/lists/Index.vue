<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ListChecks, Plus, Users } from '@lucide/vue';
import { ref } from 'vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { index, show, store } from '@/routes/lists';

defineProps<{ lists: { id: number; name: string; description: string | null; entries_count: number }[] }>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Audiencias', href: index() }] } });

const open = ref(false);
const form = useForm({ name: '', description: '' });
const create = () => form.submit(store(), { onSuccess: () => (open.value = false) });
</script>

<template>
    <Head title="Audiencias" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Audiencias" description="Listas de contactos para tus boletines. Los prospectos y clientes del CRM también se pueden usar como audiencia directamente al crear un boletín.">
            <template #actions><Button @click="open = true"><Plus /> Nueva lista</Button></template>
        </PageHeader>

        <p v-if="!lists.length" class="rounded-2xl border border-dashed bg-card py-14 text-center text-sm text-muted-foreground"><ListChecks class="mx-auto mb-2 size-7" />Aún no tienes listas. Crea una e importa contactos desde Excel.</p>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link v-for="l in lists" :key="l.id" :href="show(l.id)" class="flex flex-col gap-2 rounded-2xl border bg-card p-5 shadow-sm shadow-black/[0.03] transition hover:-translate-y-0.5 hover:shadow-md">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-accent text-accent-foreground"><Users class="size-5" /></div>
                    <div class="min-w-0"><h3 class="truncate font-semibold">{{ l.name }}</h3><p class="text-xs text-muted-foreground">{{ l.entries_count.toLocaleString('es-CL') }} contactos</p></div>
                </div>
                <p class="line-clamp-2 min-h-10 text-sm text-muted-foreground">{{ l.description || 'Sin descripción.' }}</p>
            </Link>
        </div>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Nueva lista</DialogTitle><DialogDescription>Después podrás importar contactos pegándolos desde Excel o CSV.</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="create">
                <FormField label="Nombre" for="ln" :error="form.errors.name" required><Input id="ln" v-model="form.name" placeholder="Ej: Corredores RM" autofocus /></FormField>
                <FormField label="Descripción" for="ld" :error="form.errors.description"><Input id="ld" v-model="form.description" /></FormField>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="open = false">Cancelar</Button><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Crear lista</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
