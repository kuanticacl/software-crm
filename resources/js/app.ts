import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'MecaniQ CRM';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#027BB9',
    },
});

// Calienta en segundo plano los módulos de las pantallas más usadas para que el cambio de página sea inmediato
// (solo con sesión iniciada, sin ahorro de datos y con la conexión en reposo).
const hot = import.meta.glob(['./pages/Dashboard.vue', './pages/Kanban.vue', './pages/leads/Index.vue', './pages/clients/Index.vue', './pages/proposals/Index.vue']);
const idle = (window as unknown as { requestIdleCallback?: (cb: () => void) => void }).requestIdleCallback ?? ((cb: () => void) => setTimeout(cb, 1500));
const saveData = (navigator as unknown as { connection?: { saveData?: boolean } }).connection?.saveData;
if (!saveData && !window.location.pathname.startsWith('/login')) {
    window.addEventListener('load', () => idle(() => Object.values(hot).forEach((load) => void load().catch(() => undefined))));
}

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
