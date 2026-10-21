<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Briefcase,
    Building,
    Mail,
    Shield,
    SlidersHorizontal,
    Building2,
    Columns3,
    FileText,
    History,
    ListChecks,
    MailX,
    Newspaper,
    Settings,
    Sparkles,
    Zap,
    Globe,
    LayoutDashboard,
    ListPlus,
    Plug,
    Trash2,
    Cable,
    Palette,
    ChartColumn,
    Eraser,
    ShieldCheck,
    UserCog,
    Users,
    FileSignature,
    Package,
    Bot,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import { edit as agencyEdit } from '@/routes/agency';
import { index as demoData } from '@/routes/demo-data';
import { index as trash } from '@/routes/trash';
import { index as clients } from '@/routes/clients';
import { index as fields } from '@/routes/fields';
import { index as kanbanIndex } from '@/routes/kanban';
import { index as leads } from '@/routes/leads';
import { index as brandIndex } from '@/routes/brand';
import { index as ai, usage as aiUsage } from '@/routes/ai';
import { index as mcpIndex } from '@/routes/ai/mcp';
import { index as apiKeys } from '@/routes/api-keys';
import { index as automations } from '@/routes/automations';
import { index as campaigns } from '@/routes/campaigns';
import { index as lists } from '@/routes/lists';
import { index as messages } from '@/routes/messages';
import { settings as emailSettings } from '@/routes/email';
import { index as roles } from '@/routes/roles';
import { index as suppressions } from '@/routes/suppressions';
import { index as templates } from '@/routes/templates';
import { index as sources } from '@/routes/sources';
import { index as stages } from '@/routes/stages';
import { index as proposals } from '@/routes/proposals';
import { index as services } from '@/routes/services';
import { index as users } from '@/routes/users';
import type { NavItem } from '@/types';

type NavGroup = { label: string; icon: Component; items: (NavItem & { permission?: string })[] };

const { can } = usePermissions();

const groups: NavGroup[] = [
    {
        label: 'Comercial',
        icon: Briefcase,
        items: [
            { title: 'Dashboard', href: dashboard(), icon: LayoutDashboard, permission: 'dashboard.view' },
            { title: 'Kanban', href: kanbanIndex(), icon: Columns3, permission: 'dashboard.view' },
            { title: 'Clientes', href: leads(), icon: Users, permission: 'leads.view' },
            { title: 'Empresas', href: clients(), icon: Building2, permission: 'clients.view' },
            { title: 'Propuestas', href: proposals(), icon: FileSignature, permission: 'proposals.view' },
            { title: 'Servicios y tarifas', href: services(), icon: Package, permission: 'services.view' },
        ],
    },
    {
        label: 'Email',
        icon: Mail,
        items: [
            { title: 'Boletines', href: campaigns(), icon: Newspaper, permission: 'campaigns.view' },
            { title: 'Plantillas', href: templates(), icon: FileText, permission: 'templates.view' },
            { title: 'Audiencias', href: lists(), icon: ListChecks, permission: 'lists.manage' },
            { title: 'Automatizaciones', href: automations(), icon: Zap, permission: 'automations.manage' },
            { title: 'Historial de envíos', href: messages(), icon: History, permission: 'email_logs.view' },
            { title: 'Bajas y rebotes', href: suppressions(), icon: MailX, permission: 'email_settings.manage' },
            { title: 'API de envío', href: apiKeys(), icon: Plug, permission: 'email_settings.manage' },
            { title: 'Configuración', href: emailSettings(), icon: Settings, permission: 'email_settings.manage' },
        ],
    },
    {
        label: 'Inteligencia artificial',
        icon: Sparkles,
        items: [
            { title: 'Proveedores', href: ai(), icon: Sparkles, permission: 'ai.manage' },
            { title: 'Agente web', href: '/ai/agentweb', icon: Bot, permission: 'ai.manage' },
            { title: 'Consumo y gastos', href: aiUsage(), icon: ChartColumn, permission: 'ai.manage' },
            { title: 'Herramientas MCP', href: mcpIndex(), icon: Cable, permission: 'mcp.manage' },
        ],
    },
    {
        label: 'Configuración CRM',
        icon: SlidersHorizontal,
        items: [
            { title: 'Etapas del Kanban', href: stages(), icon: Columns3, permission: 'stages.manage' },
            { title: 'Campos personalizados', href: fields(), icon: ListPlus, permission: 'fields.manage' },
            { title: 'Orígenes de clientes', href: sources(), icon: Cable, permission: 'sources.manage' },
            { title: 'Datos de mi empresa', href: agencyEdit(), icon: Building, permission: 'agency.manage' },
            { title: 'Identidad visual', href: brandIndex(), icon: Palette, permission: 'brand.manage' },
        ],
    },
    {
        label: 'Administración',
        icon: Shield,
        items: [
            { title: 'Usuarios', href: users(), icon: UserCog, permission: 'users.view' },
            { title: 'Roles y permisos', href: roles(), icon: ShieldCheck, permission: 'roles.view' },
            { title: 'Papelera', href: trash(), icon: Trash2, permission: 'trash.manage' },
            { title: 'Datos de prueba', href: demoData(), icon: Eraser, permission: 'demo.purge' },
        ],
    },
];

const visibleGroups = computed(() =>
    groups
        .map((g) => ({
            ...g,
            items: g.items.filter((i) => !i.permission || can(i.permission)),
        }))
        .filter((g) => g.items.length),
);

const footerNavItems: NavItem[] = [
    { title: 'quiebre.cl', href: 'https://www.quiebre.cl', icon: Globe },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain
                v-for="g in visibleGroups"
                :key="g.label"
                :label="g.label"
                :items="g.items"
                :icon="g.icon"
                collapsible
            />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
