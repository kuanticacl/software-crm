/** Cálculo del tema a partir de 4 colores base (acento, fondo, barra lateral y texto). El resultado son variables CSS completas. */

export type Picks = { primary: string; background: string; sidebar: string; text: string };
export type ThemeVars = Record<string, string>;

const clamp = (n: number) => Math.max(0, Math.min(255, Math.round(n)));

export const hexToRgb = (hex: string): [number, number, number] => {
    const h = hex.replace('#', '');
    const v = h.length === 3 ? h.split('').map((c) => c + c).join('') : h;
    return [parseInt(v.slice(0, 2), 16), parseInt(v.slice(2, 4), 16), parseInt(v.slice(4, 6), 16)];
};

export const rgbToHex = (r: number, g: number, b: number) => '#' + [r, g, b].map((n) => clamp(n).toString(16).padStart(2, '0')).join('');

/** Mezcla a con b: t = 0 → a, t = 1 → b. */
export const mix = (a: string, b: string, t: number) => {
    const [r1, g1, b1] = hexToRgb(a);
    const [r2, g2, b2] = hexToRgb(b);

    return rgbToHex(r1 + (r2 - r1) * t, g1 + (g2 - g1) * t, b1 + (b2 - b1) * t);
};

const channel = (c: number) => {
    const s = c / 255;

    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
};

export const luminance = (hex: string) => {
    const [r, g, b] = hexToRgb(hex);

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
};

export const contrast = (a: string, b: string) => {
    const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (l1 + 0.05) / (l2 + 0.05);
};

/** Texto blanco u oscuro, el que mejor contraste tenga sobre el color. */
export const readableOn = (bg: string) => (contrast(bg, '#ffffff') >= contrast(bg, '#1a1212') ? '#ffffff' : '#1a1212');

export const isHex = (v: string) => /^#[0-9a-fA-F]{6}$/.test(v);

/** Oscurece el color hasta que el texto blanco tenga al menos el contraste pedido (para botones legibles). */
export const ensureContrastWithWhite = (hex: string, min = 4.5) => {
    let c = hex;
    for (let i = 0; i < 20 && contrast(c, '#ffffff') < min; i++) c = mix(c, '#000000', 0.06);

    return c;
};

export function deriveTheme(p: Picks): { light: ThemeVars; dark: ThemeVars } {
    const primary = p.primary;
    const onPrimary = readableOn(primary);
    const ring = mix(primary, '#ffffff', 0.35);

    const light: ThemeVars = {
        '--background': p.background,
        '--foreground': p.text,
        '--card': '#ffffff',
        '--card-foreground': p.text,
        '--popover': '#ffffff',
        '--popover-foreground': p.text,
        '--primary': primary,
        '--primary-foreground': onPrimary,
        '--secondary': mix(p.background, p.text, 0.05),
        '--secondary-foreground': p.text,
        '--muted': mix(p.background, p.text, 0.06),
        '--muted-foreground': mix(p.text, p.background, 0.42),
        '--accent': mix(p.background, primary, 0.14),
        '--accent-foreground': mix(primary, '#000000', 0.32),
        '--border': mix(p.background, p.text, 0.1),
        '--input': mix(p.background, p.text, 0.15),
        '--ring': ring,
        '--chart-1': primary,
        '--sidebar': p.sidebar,
        '--sidebar-background': p.sidebar,
        '--sidebar-foreground': mix(p.text, p.sidebar, 0.1),
        '--sidebar-primary': primary,
        '--sidebar-primary-foreground': onPrimary,
        '--sidebar-accent': mix(p.sidebar, primary, 0.14),
        '--sidebar-accent-foreground': mix(primary, '#000000', 0.35),
        '--sidebar-border': mix(p.sidebar, p.text, 0.1),
        '--sidebar-ring': ring,
    };

    // Modo oscuro: fondo derivado del color de texto, acento aclarado.
    const bgd = mix(p.text, '#000000', 0.5);
    const pd = mix(primary, '#ffffff', 0.4);
    const onPd = readableOn(pd);
    const sbd = mix(bgd, '#000000', 0.25);
    const dark: ThemeVars = {
        '--background': bgd,
        '--foreground': mix(p.background, '#ffffff', 0.2),
        '--card': mix(bgd, '#ffffff', 0.05),
        '--card-foreground': mix(p.background, '#ffffff', 0.2),
        '--popover': mix(bgd, '#ffffff', 0.05),
        '--popover-foreground': mix(p.background, '#ffffff', 0.2),
        '--primary': pd,
        '--primary-foreground': onPd,
        '--secondary': mix(bgd, '#ffffff', 0.09),
        '--secondary-foreground': mix(p.background, '#ffffff', 0.2),
        '--muted': mix(bgd, '#ffffff', 0.09),
        '--muted-foreground': mix(p.background, bgd, 0.4),
        '--accent': mix(bgd, pd, 0.2),
        '--accent-foreground': mix(pd, '#ffffff', 0.3),
        '--border': mix(bgd, p.background, 0.13),
        '--input': mix(bgd, p.background, 0.18),
        '--ring': pd,
        '--chart-1': pd,
        '--sidebar': sbd,
        '--sidebar-background': sbd,
        '--sidebar-foreground': mix(p.background, sbd, 0.15),
        '--sidebar-primary': pd,
        '--sidebar-primary-foreground': onPd,
        '--sidebar-accent': mix(sbd, pd, 0.16),
        '--sidebar-accent-foreground': mix(pd, '#ffffff', 0.3),
        '--sidebar-border': mix(sbd, p.background, 0.1),
        '--sidebar-ring': pd,
    };

    return { light, dark };
}

/** Lee un color de las variables CSS vigentes (el diseño actual) como #rrggbb. */
export function currentColor(name: string, fallback: string): string {
    if (typeof document === 'undefined') return fallback;
    const probe = document.createElement('span');
    probe.style.color = `var(${name})`;
    probe.style.display = 'none';
    document.body.appendChild(probe);
    const rgb = getComputedStyle(probe).color;
    probe.remove();
    const m = rgb.match(/\d+(\.\d+)?/g);
    if (!m || m.length < 3) return fallback;

    return rgbToHex(Number(m[0]), Number(m[1]), Number(m[2]));
}
