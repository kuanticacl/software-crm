<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Eye, FileText, Home, LogOut, Package, Receipt, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import BrandLogo from '@/components/BrandLogo.vue';
import { Toaster } from '@/components/ui/sonner';
import { cn } from '@/lib/utils';

const page = usePage();
const user = computed(() => page.props.auth.user);
const path = computed(() => page.url.split('?')[0]);

const nav = [
    { label: 'Inicio', href: '/portal', icon: Home, exact: true },
    { label: 'Propuestas', href: '/portal/propuestas', icon: FileText },
    { label: 'Servicios', href: '/portal/servicios', icon: Package },
    { label: 'Cobros', href: '/portal/facturas', icon: Receipt },
    { label: 'Mi cuenta', href: '/portal/cuenta', icon: UserRound },
];
const active = (n: (typeof nav)[number]) => (n.exact ? path.value === n.href : path.value.startsWith(n.href));
const preview = computed(() => page.props.portal_preview as { client: string | null; user: string | null } | null);
const logout = () => router.post('/logout');
const exitPreview = () => router.post('/portal-preview/exit');
</script>

<template>
    <div class="min-h-svh bg-muted/40">
        <div v-if="preview" class="sticky top-0 z-40 flex flex-wrap items-center justify-center gap-x-4 gap-y-1 bg-amber-400 px-4 py-2 text-sm font-medium text-amber-950">
            <span class="flex items-center gap-2"><Eye class="size-4" /> Vista previa: así ve el portal <strong>{{ preview.user ?? preview.client }}</strong><span v-if="preview.user"> ({{ preview.client }})</span> · solo lectura</span>
            <button type="button" class="rounded-full bg-amber-950 px-3 py-1 text-xs font-semibold text-amber-50 hover:bg-amber-900" @click="exitPreview">Salir de la vista previa</button>
        </div>
        <header :class="['sticky z-30 border-b bg-card/95 text-foreground backdrop-blur', preview ? 'top-[2.4rem]' : 'top-0']">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-4 md:px-6">
                <Link href="/portal" class="flex items-center gap-3">
                    <BrandLogo class="h-12 shrink-0 text-foreground" />
                    <span class="hidden rounded-full bg-primary/10 px-2.5 py-0.5 text-primary text-[10px] font-semibold tracking-wide uppercase sm:inline">Portal de clientes</span>
                </Link>

                <nav class="ml-6 hidden items-center gap-1 md:flex" aria-label="Principal">
                    <Link v-for="n in nav" :key="n.href" :href="n.href" prefetch :class="cn('inline-flex items-center gap-2 rounded-full px-3.5 py-2 text-sm font-medium transition-colors', active(n) ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground')"><component :is="n.icon" class="size-4" />{{ n.label }}</Link>
                </nav>

                <div class="ml-auto flex items-center gap-3">
                    <span class="hidden max-w-48 truncate text-sm text-muted-foreground lg:block">{{ preview ? (preview.user ?? preview.client) : user.name }}</span>
                    <button v-if="!preview" type="button" class="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" title="Cerrar sesión" @click="logout"><LogOut class="size-4" /><span class="hidden sm:inline">Salir</span></button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 pt-6 pb-28 md:px-6 md:pb-12"><slot /></main>

        <!-- Navegación inferior en móvil -->
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t bg-card md:hidden" aria-label="Principal">
            <ul class="grid grid-cols-5">
                <li v-for="n in nav" :key="n.href">
                    <Link :href="n.href" :class="cn('flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-medium', active(n) ? 'text-primary' : 'text-muted-foreground')"><component :is="n.icon" class="size-5" />{{ n.label }}</Link>
                </li>
            </ul>
        </nav>
        <Toaster />
    </div>
</template>
