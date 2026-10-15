<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { ref, watch } from 'vue';
import LeadDetails from '@/components/leads/LeadDetails.vue';
import LeadPanel from '@/components/leads/LeadPanel.vue';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { sendJson } from '@/lib/http';
import { panel, show } from '@/routes/leads';
import type { PanelData } from '@/types';

const props = defineProps<{ leadId: number | null; tags?: string[] }>();
const emit = defineEmits<{ close: []; changed: [] }>();

const data = ref<PanelData | null>(null);
const error = ref(false);

const load = async (silent = false) => {
    if (!props.leadId) return;
    if (!silent) data.value = null;
    error.value = false;
    try {
        data.value = await sendJson<PanelData>('GET', panel(props.leadId).url);
    } catch {
        error.value = true;
    }
};

watch(() => props.leadId, (id) => (id ? load() : (data.value = null)), { immediate: true });

const onChanged = async () => {
    await load(true);
    emit('changed');
};
</script>

<template>
    <Sheet :open="!!leadId" @update:open="(v: boolean) => !v && emit('close')">
        <SheetContent side="right" class="w-full gap-0 overflow-hidden p-0 sm:max-w-xl">
            <SheetHeader class="shrink-0 border-b bg-background px-5 py-4 pr-12">
                <SheetTitle class="truncate text-lg">{{ data?.lead.full_name ?? 'Cargando…' }}</SheetTitle>
                <SheetDescription class="flex items-center gap-3 text-xs">
                    <span>{{ [data?.lead.job_title, data?.lead.company].filter(Boolean).join(' · ') || 'Cliente' }}</span>
                    <Link v-if="leadId" :href="show(leadId)" class="inline-flex items-center gap-1 text-primary hover:underline">
                        <ExternalLink class="size-3" /> Abrir ficha completa
                    </Link>
                </SheetDescription>
            </SheetHeader>

            <div class="scroll-cues min-h-0 flex-1 p-4 [overflow-wrap:anywhere] sm:p-5">
                <div v-if="error" class="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm">
                    No se pudo cargar el cliente. <button class="underline" @click="load()">Reintentar</button>
                </div>
                <div v-else-if="!data" class="flex flex-col gap-3">
                    <Skeleton class="h-40 w-full rounded-2xl" />
                    <Skeleton class="h-10 w-2/3 rounded-xl" />
                    <Skeleton class="h-32 w-full rounded-2xl" />
                </div>
                <LeadPanel v-else :data="data" layout="drawer" :tag-suggestions="tags" @changed="onChanged">
                    <template #details><LeadDetails :data="data" /></template>
                </LeadPanel>
            </div>
        </SheetContent>
    </Sheet>
</template>
