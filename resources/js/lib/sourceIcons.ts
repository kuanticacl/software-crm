import {
    Building2,
    CalendarDays,
    FileText,
    Globe,
    Handshake,
    LayoutTemplate,
    Mail,
    Megaphone,
    MessageCircle,
    Pencil,
    Phone,
    QrCode,
    Search,
    Star,
    Store,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';

/** Íconos disponibles para los orígenes (nombre lucide → componente). */
export const sourceIcons: Record<string, Component> = {
    globe: Globe,
    'layout-template': LayoutTemplate,
    megaphone: Megaphone,
    search: Search,
    'message-circle': MessageCircle,
    handshake: Handshake,
    pencil: Pencil,
    mail: Mail,
    phone: Phone,
    'calendar-days': CalendarDays,
    store: Store,
    'building-2': Building2,
    star: Star,
    zap: Zap,
    'qr-code': QrCode,
    'file-text': FileText,
};

export const sourceIconNames = Object.keys(sourceIcons);

/** Paleta de la marca MecaniQ para elegir colores rápido. */
export const brandColors = [
    '#027BB9',
    '#1AA0E4',
    '#0D9F85',
    '#6419DB',
    '#DF1E79',
    '#4A8CFF',
    '#FFA165',
    '#8CDE2F',
    '#25D366',
    '#8A8A8A',
    '#393939',
];
