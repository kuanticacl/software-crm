import type { LeadCard } from '@/types';

export const priorityMeta: Record<string, { label: string; color: string }> = {
    low: { label: 'Baja', color: '#8A8A8A' },
    normal: { label: 'Normal', color: '#1AA0E4' },
    high: { label: 'Alta', color: '#FFA165' },
    urgent: { label: 'Urgente', color: '#DC2626' },
};

const clp = new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 });
export const formatMoney = (v: number | null | undefined): string => (v ? clp.format(v) : '');

/** Versión compacta para encabezados: $1,2 M / $350 mil */
export function formatMoneyShort(v: number | null | undefined): string {
    if (!v) return '';
    if (v >= 1_000_000) return `$${(v / 1_000_000).toLocaleString('es-CL', { maximumFractionDigits: 1 })} M`;
    if (v >= 1_000) return `$${Math.round(v / 1_000).toLocaleString('es-CL')} mil`;
    return `$${v}`;
}

const DAY = 86_400_000;
export const daysSince = (iso: string | null | undefined): number =>
    iso ? Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / DAY)) : 0;

export type FollowUp = { state: 'overdue' | 'today' | 'soon' | 'later'; label: string };

/** Estado del próximo seguimiento para colorear la tarjeta. */
export function followUpInfo(iso: string | null | undefined): FollowUp | null {
    if (!iso) return null;
    const date = new Date(iso);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const dayDiff = Math.floor((new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime() - startToday) / DAY);
    const time = date.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });

    if (date.getTime() < now.getTime() && dayDiff < 0) {
        const d = Math.abs(dayDiff);
        return { state: 'overdue', label: `Vencido hace ${d} ${d === 1 ? 'día' : 'días'}` };
    }
    if (date.getTime() < now.getTime()) return { state: 'overdue', label: `Vencido (${time})` };
    if (dayDiff === 0) return { state: 'today', label: `Hoy ${time}` };
    if (dayDiff === 1) return { state: 'soon', label: `Mañana ${time}` };
    return {
        state: dayDiff <= 3 ? 'soon' : 'later',
        label: date.toLocaleDateString('es-CL', { weekday: 'short', day: 'numeric', month: 'short' }),
    };
}

export const followUpClass: Record<FollowUp['state'], string> = {
    overdue: 'bg-destructive/10 text-destructive',
    today: 'bg-[#A9606B]/10 text-[#C23F00]',
    soon: 'bg-[#1AA0E4]/10 text-[#0B78AE]',
    later: 'bg-muted text-muted-foreground',
};

export function whatsappUrl(phone: string | null): string | null {
    if (!phone) return null;
    const digits = phone.replace(/\D/g, '');
    return digits ? `https://wa.me/${digits}` : null;
}

export const isClosed = (lead: Pick<LeadCard, 'closed_at'>): boolean => !!lead.closed_at;

export const lostReasons = [
    'Sin respuesta',
    'Precio / presupuesto',
    'Eligió otro proveedor',
    'No es el perfil',
    'Duplicado',
    'Proyecto cancelado',
];

/** ISO → valor para <input type="datetime-local"> en hora local. */
export function toLocalInput(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Temperatura del lead según su puntaje (A = más caliente). */
export const gradeMeta: Record<string, { label: string; short: string; color: string }> = {
    A: { label: 'A · Caliente', short: 'Caliente', color: '#A9606B' },
    B: { label: 'B · Tibio', short: 'Tibio', color: '#FFA165' },
    C: { label: 'C · Frío', short: 'Frío', color: '#1AA0E4' },
    D: { label: 'D · Bajo', short: 'Bajo', color: '#8A8A8A' },
};

const ufFmt = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ufShort = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 });
/** UF con 2 decimales: «UF 245,50». */
export const formatUf = (v: number | null | undefined): string => `UF ${ufFmt.format(v ?? 0)}`;
/** Monto en la moneda de la propuesta (UF o pesos). */
export const formatAmount = (v: number | null | undefined, currency: string): string => (currency === 'UF' ? formatUf(v) : formatMoney(v) || '$0');
export const formatAmountShort = (v: number | null | undefined, currency: string): string => (currency === 'UF' ? `UF ${ufShort.format(v ?? 0)}` : formatMoneyShort(v));
/** Valor de la UF: «$41.122,74». */
export const formatUfValue = (v: number | null | undefined): string => (v ? `$${ufFmt.format(v)}` : '—');
