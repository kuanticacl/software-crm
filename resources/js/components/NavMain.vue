<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { useStorage } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, watch } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

const props = defineProps<{
    items: NavItem[];
    label?: string;
    /** Muestra el grupo como desplegable (cerrado por defecto, abierto si contiene la página actual). */
    collapsible?: boolean;
    /** Ícono del grupo: en el sidebar compacto el grupo es un solo ícono que abre un menú con sus opciones. */
    icon?: Component;
}>();

const { isCurrentOrParentUrl } = useCurrentUrl();
const { state, isMobile } = useSidebar();

/** Grupos abiertos por el usuario, por navegador. */
const openGroups = useStorage<Record<string, boolean>>('qb-sidebar-groups', {});

const hasActive = computed(() =>
    props.items.some((i) => isCurrentOrParentUrl(i.href)),
);

// Al entrar a una página de un grupo cerrado, se abre para que se vea dónde estás.
watch(
    hasActive,
    (active) => {
        if (active && props.label) openGroups.value[props.label] = true;
    },
    { immediate: true },
);

// Sidebar en modo íconos: sin títulos de grupo. Con ícono de grupo se muestra un menú; si no, todos los ítems.
const iconMode = computed(() => state.value === 'collapsed' && !isMobile.value);
const asMenu = computed(() => iconMode.value && !!props.icon && !!props.label);

const open = computed({
    get: () =>
        !props.collapsible ||
        !props.label ||
        iconMode.value ||
        !!openGroups.value[props.label],
    set: (v: boolean) => {
        if (props.label) openGroups.value[props.label] = v;
    },
});

// En modo íconos el tooltip incluye el grupo («Comercial · Kanban»), porque no se ve el título.
const tooltip = (title: string) =>
    props.label ? `${props.label} · ${title}` : title;
</script>

<template>
    <SidebarGroup v-if="asMenu" class="px-2 py-0">
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <SidebarMenuButton
                            :is-active="hasActive"
                            :tooltip="label"
                        >
                            <component :is="icon" />
                            <span>{{ label }}</span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="right"
                        align="start"
                        :side-offset="8"
                        class="min-w-56"
                    >
                        <DropdownMenuLabel
                            class="text-xs text-muted-foreground"
                            >{{ label }}</DropdownMenuLabel
                        >
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-for="item in items"
                            :key="item.title"
                            as-child
                            :class="
                                isCurrentOrParentUrl(item.href)
                                    ? 'bg-accent font-medium'
                                    : ''
                            "
                        >
                            <Link
                                :href="item.href"
                                prefetch
                                class="cursor-pointer"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
    <Collapsible v-else v-model:open="open" as-child>
        <SidebarGroup class="px-2 py-0">
            <SidebarGroupLabel v-if="label && !collapsible">{{
                label
            }}</SidebarGroupLabel>
            <SidebarGroupLabel
                v-else-if="label"
                as-child
                class="w-full cursor-pointer text-sm hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
            >
                <CollapsibleTrigger>
                    <span :class="hasActive ? 'text-sidebar-foreground' : ''">{{
                        label
                    }}</span>
                    <ChevronRight
                        class="ml-auto transition-transform duration-200"
                        :class="open ? 'rotate-90' : ''"
                    />
                </CollapsibleTrigger>
            </SidebarGroupLabel>
            <CollapsibleContent>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in items" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentOrParentUrl(item.href)"
                            :tooltip="tooltip(item.title)"
                        >
                            <Link :href="item.href" prefetch>
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </CollapsibleContent>
        </SidebarGroup>
    </Collapsible>
</template>
