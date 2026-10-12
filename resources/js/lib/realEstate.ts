// Formatos del CRM inmobiliario (precios en UF con 2 decimales, pesos sin decimales, superficies en m²).
const ufNf = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ufShortNf = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 });
const clpNf = new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 });
const m2Nf = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 2 });

export const uf = (v: number | null | undefined): string => `${ufNf.format(Number(v ?? 0))} UF`;
export const ufShort = (v: number | null | undefined): string => `${ufShortNf.format(Number(v ?? 0))} UF`;
export const clp = (v: number | null | undefined): string => clpNf.format(Math.round(Number(v ?? 0)));
export const m2 = (v: number | null | undefined): string => (v ? `${m2Nf.format(Number(v))} m²` : '—');
export const pct = (v: number | null | undefined): string => `${m2Nf.format(Number(v ?? 0))}%`;
export const toClp = (vUf: number | null | undefined, ufValue: number | null | undefined): number => Math.round(Number(vUf ?? 0) * Number(ufValue ?? 0));

/** Dividendo mensual (sistema francés, sin seguros): igual al cálculo del servidor. */
export function dividend(credit: number, annualRate: number, years: number): number {
    const n = Math.max(1, years) * 12;
    if (credit <= 0) return 0;
    const i = annualRate / 100 / 12;
    return Math.round((i > 0 ? (credit * i) / (1 - (1 + i) ** -n) : credit / n) * 100) / 100;
}

export const r2 = (n: number): number => Math.round(n * 100) / 100;

export type UnitRow = {
    id: number;
    project_id: number;
    type: string;
    code: string;
    tower: string | null;
    model: string | null;
    floor: number | null;
    orientation: string | null;
    bedrooms: number | null;
    bathrooms: number | null;
    program: string | null;
    program_label: string | null;
    surface_useful: number | null;
    surface_terrace: number | null;
    surface_total: number | null;
    surface_lot: number | null;
    list_price: number;
    status: string;
    notes: string | null;
    features: string[];
    is_main: boolean;
    floor_plan: string | null;
};

export type ProjectCard = {
    id: number;
    name: string;
    slug: string;
    developer: string | null;
    commune: string | null;
    city: string | null;
    stage: string;
    stage_label: string;
    delivery_date: string | null;
    delivery_note: string | null;
    color: string;
    is_active: boolean;
    location: string;
    cover: string | null;
    executives: { id: number; name: string; is_lead: boolean }[];
};
