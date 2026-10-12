<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Eye, ExternalLink, KeyRound, MailPlus, Plus, Power, Trash2, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataCard from '@/components/DataCard.vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { fmtDate } from '@/lib/billingUi';

type PortalUser = { id: number; name: string; email: string; is_active: boolean; must_change_password: boolean; last_login_at: string | null; invited_at: string | null };

const props = defineProps<{
    clientId: number;
    portal: { url: string; users: PortalUser[] };
    contacts: { id: number; full_name: string; email: string | null }[];
}>();

const open = ref(false);
const form = useForm({ name: '', email: '', phone: '', lead_id: '' as number | string });
const pick = () => {
    const c = props.contacts.find((x) => x.id === Number(form.lead_id));
    if (c) { form.name = c.full_name; form.email = c.email ?? ''; }
};
const create = () => form.post(`/clients/${props.clientId}/portal-users`, { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } });

const busy = ref<number | null>(null);
const act = (u: PortalUser, action: 'reset' | 'toggle') => {
    busy.value = u.id;
    router.post(`/portal-users/${u.id}/${action}`, {}, { preserveScroll: true, onFinish: () => (busy.value = null) });
};
const toDelete = ref<PortalUser | null>(null);
const preview = (userId?: number) => router.post(`/clients/${props.clientId}/portal-preview`, userId ? { user_id: userId } : {});
const seen = (u: PortalUser) => (u.last_login_at ? `Último ingreso ${fmtDate(u.last_login_at)}` : u.invited_at ? `Invitado ${fmtDate(u.invited_at)} · aún no ingresa` : '');
</script>

<template>
    <DataCard>
        <div class="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
            <span class="flex items-center gap-2 font-semibold"><KeyRound class="size-4" /> Acceso al portal de clientes</span>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" title="Abre el portal tal como lo ve el cliente (solo lectura)" @click="preview()"><Eye /> Ver como cliente</Button>
                <Button size="sm" variant="outline" @click="open = true"><Plus /> Dar acceso</Button>
            </div>
        </div>
        <ul v-if="portal.users.length" class="divide-y">
            <li v-for="u in portal.users" :key="u.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-accent-foreground"><UserRound class="size-4" /></div>
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ u.name }} <span v-if="!u.is_active" class="ml-1 rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">Desactivado</span></p>
                        <p class="truncate text-xs text-muted-foreground">{{ u.email }} · {{ seen(u) }}</p>
                    </div>
                </div>
                <div class="flex gap-1">
                    <Button variant="ghost" size="icon-sm" title="Ver el portal como este usuario" @click="preview(u.id)"><Eye /></Button>
                    <Button variant="ghost" size="sm" :disabled="busy === u.id" title="Genera una contraseña nueva y la envía por correo" @click="act(u, 'reset')"><Spinner v-if="busy === u.id" /><MailPlus v-else /> Reenviar acceso</Button>
                    <Button variant="ghost" size="icon-sm" :title="u.is_active ? 'Desactivar' : 'Reactivar'" @click="act(u, 'toggle')"><Power /></Button>
                    <Button variant="ghost" size="icon-sm" class="text-destructive" title="Eliminar acceso" @click="toDelete = u"><Trash2 /></Button>
                </div>
            </li>
        </ul>
        <p v-else class="px-5 py-6 text-sm text-muted-foreground">Nadie de este cliente tiene acceso todavía. Al dar acceso se genera una contraseña y se envía un correo con los datos de ingreso.</p>
        <div class="flex items-center gap-1 border-t px-5 py-2.5 text-xs text-muted-foreground"><ExternalLink class="size-3" /> Portal: <a :href="portal.url" target="_blank" rel="noopener" class="text-primary hover:underline">{{ portal.url }}</a></div>
    </DataCard>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Dar acceso al portal</DialogTitle><DialogDescription>Generamos la contraseña y enviamos un correo de bienvenida con el correo, la contraseña y el enlace de ingreso. Verá solo lo de su cliente.</DialogDescription></DialogHeader>
            <form class="grid gap-4" @submit.prevent="create">
                <FormField v-if="contacts.length" label="Contacto del cliente" for="pa-lead" hint="Opcional: precarga sus datos."><NativeSelect id="pa-lead" v-model="form.lead_id" @update:model-value="pick"><option value="">Otra persona</option><option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.full_name }}</option></NativeSelect></FormField>
                <FormField label="Nombre" for="pa-name" required :error="form.errors.name"><Input id="pa-name" v-model="form.name" /></FormField>
                <FormField label="Correo" for="pa-email" required :error="form.errors.email"><Input id="pa-email" v-model="form.email" type="email" /></FormField>
                <FormField label="Teléfono" for="pa-phone" :error="form.errors.phone"><Input id="pa-phone" v-model="form.phone" /></FormField>
                <DialogFooter class="gap-2"><Button type="button" variant="outline" @click="open = false">Cancelar</Button><Button type="submit" :disabled="form.processing"><Spinner v-if="form.processing" /> Crear y enviar correo</Button></DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog :open="!!toDelete" title="Eliminar acceso" :description="`${toDelete?.name} ya no podrá ingresar al portal.`" confirm-label="Eliminar" @update:open="(v: boolean) => !v && (toDelete = null)" @confirm="toDelete && router.delete(`/portal-users/${toDelete.id}`, { preserveScroll: true }); toDelete = null" />
</template>
