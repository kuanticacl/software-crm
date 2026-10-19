/**
 * Editor visual de plantillas: modelo de bloques y compilador a HTML para email
 * (tablas + estilos en línea + media query para móviles, compatible con Gmail/Outlook).
 * Las variables ({{ nombre }}) se dejan intactas: las resuelve el servidor al enviar.
 */

import { isHtml, sanitizeHtml } from '@/lib/richText';

export type BlockType =
    | 'header'
    | 'heading'
    | 'text'
    | 'image'
    | 'button'
    | 'columns'
    | 'list'
    | 'divider'
    | 'spacer'
    | 'social'
    | 'footer'
    | 'html';

export type Block = { id: string; type: BlockType; props: Record<string, any> };

export type DesignSettings = {
    bg: string;
    contentBg: string;
    width: number;
    font: string;
    textColor: string;
    linkColor: string;
    radius: number;
};

export type Design = { settings: DesignSettings; blocks: Block[] };

export type Field = {
    key: string;
    label: string;
    type: 'text' | 'textarea' | 'rich' | 'color' | 'number' | 'select' | 'align' | 'toggle' | 'image' | 'url';
    options?: { value: string; label: string }[];
    min?: number;
    max?: number;
    hint?: string;
    variables?: boolean; // permite insertar {{variables}}
};

export const FONTS = [
    { value: 'Arial, Helvetica, sans-serif', label: 'Arial / Helvetica' },
    { value: "'Trebuchet MS', Arial, sans-serif", label: 'Trebuchet MS' },
    { value: 'Verdana, Geneva, sans-serif', label: 'Verdana' },
    { value: 'Georgia, serif', label: 'Georgia (serif)' },
    { value: "Tahoma, Geneva, sans-serif", label: 'Tahoma' },
];

export const defaultSettings = (): DesignSettings => ({
    bg: '#F4F4F4',
    contentBg: '#FFFFFF',
    width: 600,
    font: FONTS[0].value,
    textColor: '#393939',
    linkColor: '#027BB9',
    radius: 16,
});

const uid = () => Math.random().toString(36).slice(2, 10);

const common: Field[] = [
    { key: 'padY', label: 'Espacio vertical (px)', type: 'number', min: 0, max: 80 },
    { key: 'padX', label: 'Espacio lateral (px)', type: 'number', min: 0, max: 80 },
    { key: 'bg', label: 'Color de fondo', type: 'color', hint: 'Vacío = transparente' },
];

const align: Field = { key: 'align', label: 'Alineación', type: 'align' };

type Meta = { label: string; description: string; icon: string; props: () => Record<string, any>; fields: Field[] };

export const BLOCKS: Record<BlockType, Meta> = {
    header: {
        label: 'Encabezado',
        description: 'Logo o nombre de marca',
        icon: 'panel-top',
        props: () => ({ logoUrl: '', logoWidth: 140, brandText: 'MecaniQ', brandColor: '#027BB9', align: 'center', padY: 24, padX: 32, bg: '' }),
        fields: [
            { key: 'logoUrl', label: 'Logo (imagen PNG/JPG)', type: 'image', hint: 'Si lo dejas vacío se muestra el nombre de marca en texto.' },
            { key: 'logoWidth', label: 'Ancho del logo (px)', type: 'number', min: 40, max: 400 },
            { key: 'brandText', label: 'Texto de marca', type: 'text' },
            { key: 'brandColor', label: 'Color del texto', type: 'color' },
            align,
            ...common,
        ],
    },
    heading: {
        label: 'Título',
        description: 'Encabezado grande',
        icon: 'heading',
        props: () => ({ text: 'Tu título aquí', size: 28, color: '', weight: '700', align: 'left', padY: 12, padX: 32, bg: '' }),
        fields: [
            { key: 'text', label: 'Texto', type: 'textarea', variables: true },
            { key: 'size', label: 'Tamaño (px)', type: 'number', min: 14, max: 56 },
            { key: 'color', label: 'Color', type: 'color', hint: 'Vacío = color del texto general' },
            { key: 'weight', label: 'Grosor', type: 'select', options: [{ value: '400', label: 'Normal' }, { value: '600', label: 'Seminegrita' }, { value: '700', label: 'Negrita' }] },
            align,
            ...common,
        ],
    },
    text: {
        label: 'Texto',
        description: 'Párrafo con **negrita**, *cursiva* y [enlaces](url)',
        icon: 'align-left',
        props: () => ({ text: 'Escribe aquí tu mensaje. Puedes usar **negrita**, *cursiva*, [enlaces](https://www.mecaniq.cl) y variables como {{ first_name }}.', size: 16, color: '', lineHeight: 1.6, align: 'left', padY: 8, padX: 32, bg: '' }),
        fields: [
            { key: 'text', label: 'Contenido', type: 'rich', variables: true, hint: 'Usa la barra para negrita, listas y enlaces. Inserta variables con { }.' },
            { key: 'size', label: 'Tamaño (px)', type: 'number', min: 11, max: 28 },
            { key: 'color', label: 'Color', type: 'color' },
            { key: 'lineHeight', label: 'Interlineado', type: 'number', min: 1, max: 2.4 },
            align,
            ...common,
        ],
    },
    image: {
        label: 'Imagen',
        description: 'Imagen con enlace opcional',
        icon: 'image',
        props: () => ({ src: '', alt: '', href: '', width: 100, radius: 8, align: 'center', padY: 12, padX: 32, bg: '' }),
        fields: [
            { key: 'src', label: 'Imagen', type: 'image', variables: true },
            { key: 'alt', label: 'Texto alternativo', type: 'text' },
            { key: 'href', label: 'Enlace al hacer clic', type: 'url', variables: true },
            { key: 'width', label: 'Ancho (%)', type: 'number', min: 10, max: 100 },
            { key: 'radius', label: 'Bordes redondeados (px)', type: 'number', min: 0, max: 40 },
            align,
            ...common,
        ],
    },
    button: {
        label: 'Botón',
        description: 'Llamado a la acción',
        icon: 'mouse-pointer-click',
        props: () => ({ label: 'Agenda tu demo', href: 'https://www.mecaniq.cl', bg: '#027BB9', color: '#FFFFFF', radius: 999, size: 16, align: 'center', fullWidth: false, padY: 16, padX: 32, rowBg: '' }),
        fields: [
            { key: 'label', label: 'Texto del botón', type: 'text', variables: true },
            { key: 'href', label: 'Enlace', type: 'url', variables: true },
            { key: 'bg', label: 'Color del botón', type: 'color' },
            { key: 'color', label: 'Color del texto', type: 'color' },
            { key: 'radius', label: 'Redondeo (px)', type: 'number', min: 0, max: 999 },
            { key: 'size', label: 'Tamaño de texto (px)', type: 'number', min: 12, max: 24 },
            { key: 'fullWidth', label: 'Ancho completo', type: 'toggle' },
            align,
            { key: 'padY', label: 'Espacio vertical (px)', type: 'number', min: 0, max: 80 },
            { key: 'padX', label: 'Espacio lateral (px)', type: 'number', min: 0, max: 80 },
            { key: 'rowBg', label: 'Color de fondo de la fila', type: 'color' },
        ],
    },
    columns: {
        label: 'Imagen + texto',
        description: 'Dos columnas que se apilan en móvil',
        icon: 'columns-2',
        props: () => ({ imageUrl: '', imageSide: 'left', title: 'Destacado', text: 'Cuenta algo breve y atractivo aquí.', buttonLabel: 'Ver más', buttonUrl: 'https://www.mecaniq.cl', buttonBg: '#027BB9', padY: 16, padX: 32, bg: '' }),
        fields: [
            { key: 'imageUrl', label: 'Imagen', type: 'image', variables: true },
            { key: 'imageSide', label: 'Posición de la imagen', type: 'select', options: [{ value: 'left', label: 'Izquierda' }, { value: 'right', label: 'Derecha' }] },
            { key: 'title', label: 'Título', type: 'text', variables: true },
            { key: 'text', label: 'Texto', type: 'textarea', variables: true },
            { key: 'buttonLabel', label: 'Texto del botón', type: 'text', hint: 'Vacío = sin botón' },
            { key: 'buttonUrl', label: 'Enlace del botón', type: 'url', variables: true },
            { key: 'buttonBg', label: 'Color del botón', type: 'color' },
            ...common,
        ],
    },
    list: {
        label: 'Lista dinámica',
        description: 'Repite una tarjeta por cada elemento recibido (proyectos, propiedades…)',
        icon: 'list-ordered',
        props: () => ({ collection: 'proyectos', imageKey: 'imagen', titleKey: 'nombre', textKey: 'descripcion', priceKey: 'precio', urlKey: 'url', buttonLabel: 'Ver detalle', buttonBg: '#027BB9', emptyText: '', padY: 12, padX: 32, bg: '' }),
        fields: [
            { key: 'collection', label: 'Variable con la lista', type: 'text', hint: 'En la API envía un arreglo: "proyectos": [{ "nombre": "…", "imagen": "…" }]' },
            { key: 'imageKey', label: 'Campo de imagen', type: 'text' },
            { key: 'titleKey', label: 'Campo de título', type: 'text' },
            { key: 'textKey', label: 'Campo de descripción', type: 'text' },
            { key: 'priceKey', label: 'Campo de precio', type: 'text', hint: 'Se muestra con formato $1.234.567' },
            { key: 'urlKey', label: 'Campo de enlace', type: 'text' },
            { key: 'buttonLabel', label: 'Texto del botón', type: 'text', hint: 'Vacío = sin botón' },
            { key: 'buttonBg', label: 'Color del botón', type: 'color' },
            { key: 'emptyText', label: 'Texto si la lista viene vacía', type: 'text', variables: true },
            ...common,
        ],
    },
    divider: {
        label: 'Divisor',
        description: 'Línea horizontal',
        icon: 'minus',
        props: () => ({ color: '#E4E4E4', thickness: 1, padY: 12, padX: 32, bg: '' }),
        fields: [
            { key: 'color', label: 'Color', type: 'color' },
            { key: 'thickness', label: 'Grosor (px)', type: 'number', min: 1, max: 8 },
            ...common,
        ],
    },
    spacer: {
        label: 'Espaciador',
        description: 'Espacio en blanco',
        icon: 'move-vertical',
        props: () => ({ height: 24, bg: '' }),
        fields: [
            { key: 'height', label: 'Alto (px)', type: 'number', min: 4, max: 120 },
            { key: 'bg', label: 'Color de fondo', type: 'color' },
        ],
    },
    social: {
        label: 'Redes sociales',
        description: 'Enlaces a tus redes',
        icon: 'share-2',
        props: () => ({ web: 'https://www.mecaniq.cl', instagram: '', facebook: '', linkedin: '', youtube: '', align: 'center', color: '#707070', padY: 12, padX: 32, bg: '' }),
        fields: [
            { key: 'web', label: 'Sitio web', type: 'url' },
            { key: 'instagram', label: 'Instagram', type: 'url' },
            { key: 'facebook', label: 'Facebook', type: 'url' },
            { key: 'linkedin', label: 'LinkedIn', type: 'url' },
            { key: 'youtube', label: 'YouTube', type: 'url' },
            { key: 'color', label: 'Color de los enlaces', type: 'color' },
            align,
            ...common,
        ],
    },
    footer: {
        label: 'Pie de página',
        description: 'Datos legales y enlace de baja',
        icon: 'panel-bottom',
        props: () => ({ company: '{{ company_name }}', address: '', legal: 'Recibes este correo porque te registraste en nuestro sitio.', showUnsubscribe: true, unsubscribeText: 'Darme de baja', showView: false, color: '#8A8A8A', align: 'center', padY: 24, padX: 32, bg: '#FAFAFA' }),
        fields: [
            { key: 'company', label: 'Empresa', type: 'text', variables: true },
            { key: 'address', label: 'Dirección', type: 'text' },
            { key: 'legal', label: 'Texto legal', type: 'textarea', variables: true },
            { key: 'showUnsubscribe', label: 'Mostrar enlace de baja', type: 'toggle' },
            { key: 'unsubscribeText', label: 'Texto del enlace de baja', type: 'text' },
            { key: 'showView', label: 'Mostrar «Ver en el navegador»', type: 'toggle' },
            { key: 'color', label: 'Color del texto', type: 'color' },
            align,
            ...common,
        ],
    },
    html: {
        label: 'HTML libre',
        description: 'Código HTML propio',
        icon: 'code',
        props: () => ({ code: '<p style="margin:0;text-align:center">Tu HTML aquí</p>', padY: 8, padX: 0, bg: '' }),
        fields: [
            { key: 'code', label: 'Código HTML', type: 'textarea', variables: true, hint: 'Usa estilos en línea; evita <script> y CSS externo.' },
            ...common,
        ],
    },
};

export const newBlock = (type: BlockType): Block => ({ id: uid(), type, props: BLOCKS[type].props() });

// ---------------------------------------------------------------------------------- compilación

const escHtml = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const attr = (s: unknown) => String(s ?? '').replace(/"/g, '&quot;');

/** Markdown mínimo: **negrita**, *cursiva*, [texto](url) y saltos de línea. */
export function richText(input: unknown, linkColor: string): string {
    // Texto del editor enriquecido (HTML): se sanea y se le dan estilos en línea compatibles con email.
    if (isHtml(String(input ?? ''))) {
        return sanitizeHtml(String(input))
            .replace(/<p>/g, '<p style="margin:0 0 12px">')
            .replace(/<ul>/g, '<ul style="margin:0 0 12px;padding-left:22px">')
            .replace(/<ol>/g, '<ol style="margin:0 0 12px;padding-left:22px">')
            .replace(/<li>/g, '<li style="margin:0 0 4px">')
            .replace(/<h3>/g, '<h3 style="margin:0 0 8px;font-size:18px">')
            .replace(/<a href="([^"]+)"[^>]*>/g, (_, url) => `<a href="${attr(url)}" style="color:${linkColor};text-decoration:underline">`);
    }
    let t = escHtml(input);
    t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, url) => `<a href="${attr(url)}" style="color:${linkColor};text-decoration:underline">${label}</a>`);
    t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');

    return t.replace(/\r?\n/g, '<br>');
}

type Ctx = { s: DesignSettings; editor: boolean };

const row = (b: Block, ctx: Ctx, inner: string, o: { bg?: string; padY?: number; padX?: number } = {}) => {
    const p = b.props;
    const padY = o.padY ?? p.padY ?? 0;
    const padX = o.padX ?? p.padX ?? 0;
    const bg = o.bg ?? p.bg;
    const style = `padding:${padY}px ${padX}px;${bg ? `background-color:${bg};` : ''}`;

    return `<tr><td class="px" ${ctx.editor ? `data-bid="${b.id}" ` : ''}style="${style}">${inner}</td></tr>`;
};

const button = (label: string, href: string, bg: string, color: string, radius: number, size: number, align: string, full = false) =>
    `<table role="presentation" cellpadding="0" cellspacing="0" border="0" ${full ? 'width="100%"' : `align="${align}"`} style="${full ? '' : 'display:inline-table;'}"><tr><td align="center" bgcolor="${bg}" style="background-color:${bg};border-radius:${radius}px"><a href="${attr(href)}" target="_blank" style="display:block;padding:14px 28px;font-size:${size}px;line-height:1.2;font-weight:600;color:${color};text-decoration:none;border-radius:${radius}px">${escHtml(label)}</a></td></tr></table>`;

const align3 = (a: string) => (a === 'right' ? 'right' : a === 'center' ? 'center' : 'left');

function compileBlock(b: Block, ctx: Ctx): string {
    const p = b.props;
    const { s } = ctx;

    switch (b.type) {
        case 'header': {
            const inner = p.logoUrl
                ? `<img src="${attr(p.logoUrl)}" width="${p.logoWidth}" alt="${attr(p.brandText)}" style="display:inline-block;width:${p.logoWidth}px;max-width:100%;height:auto;border:0">`
                : `<span style="font-size:26px;font-weight:700;letter-spacing:-0.5px;color:${p.brandColor}">${escHtml(p.brandText)}</span>`;

            return row(b, ctx, `<div style="text-align:${align3(p.align)}">${inner}</div>`);
        }
        case 'heading':
            return row(b, ctx, `<h1 style="margin:0;font-size:${p.size}px;line-height:1.25;font-weight:${p.weight};color:${p.color || s.textColor};text-align:${align3(p.align)}">${richText(p.text, s.linkColor)}</h1>`);
        case 'text':
            return row(b, ctx, `<div style="font-size:${p.size}px;line-height:${p.lineHeight};color:${p.color || s.textColor};text-align:${align3(p.align)}">${richText(p.text, s.linkColor)}</div>`);
        case 'image': {
            if (!p.src) {
                return row(b, ctx, `<div style="border:2px dashed #CFCFCF;border-radius:8px;padding:28px;text-align:center;color:#9A9A9A;font-size:13px">Imagen: agrega una URL o sube un archivo</div>`);
            }
            const img = `<img src="${attr(p.src)}" alt="${attr(p.alt)}" width="${Math.round((s.width - 2 * (p.padX ?? 0)) * (p.width / 100))}" style="display:inline-block;width:${p.width}%;max-width:100%;height:auto;border:0;border-radius:${p.radius}px">`;
            const content = p.href ? `<a href="${attr(p.href)}" target="_blank">${img}</a>` : img;

            return row(b, ctx, `<div style="text-align:${align3(p.align)};line-height:0">${content}</div>`);
        }
        case 'button':
            return row(b, ctx, `<div style="text-align:${align3(p.align)}">${button(p.label, p.href, p.bg, p.color, p.radius, p.size, align3(p.align), p.fullWidth)}</div>`, { bg: p.rowBg });
        case 'columns': {
            const img = p.imageUrl
                ? `<img src="${attr(p.imageUrl)}" alt="" width="260" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:8px">`
                : `<div style="border:2px dashed #CFCFCF;border-radius:8px;padding:40px 12px;text-align:center;color:#9A9A9A;font-size:13px">Imagen</div>`;
            const txt = `<h2 style="margin:0 0 8px;font-size:20px;line-height:1.3;color:${s.textColor}">${richText(p.title, s.linkColor)}</h2><div style="font-size:15px;line-height:1.6;color:${s.textColor}">${richText(p.text, s.linkColor)}</div>${p.buttonLabel ? `<div style="margin-top:14px">${button(p.buttonLabel, p.buttonUrl, p.buttonBg, '#FFFFFF', 999, 14, 'left')}</div>` : ''}`;
            const col = (inner: string, pad: string) => `<div class="col" style="display:inline-block;width:100%;max-width:50%;vertical-align:top;box-sizing:border-box;${pad}">${inner}</div>`;
            const [a, c] = p.imageSide === 'right' ? [col(txt, 'padding-right:12px'), col(img, 'padding-left:12px')] : [col(img, 'padding-right:12px'), col(txt, 'padding-left:12px')];

            return row(b, ctx, `<div style="font-size:0;text-align:left">${a}${c}</div>`);
        }
        case 'list': {
            const k = (key: string) => (key ? `this.${key}` : '');
            const card = `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #E4E4E4;border-radius:12px;overflow:hidden"><tr><td>
${p.imageKey ? `{{#if ${k(p.imageKey)}}}<img src="{{ ${k(p.imageKey)} }}" alt="" width="100%" style="display:block;width:100%;height:auto;border:0">{{/if}}` : ''}
<div style="padding:16px">
${p.titleKey ? `<div style="font-size:18px;font-weight:700;color:${s.textColor}">{{ ${k(p.titleKey)} }}</div>` : ''}
${p.priceKey ? `{{#if ${k(p.priceKey)}}}<div style="margin-top:4px;font-size:16px;font-weight:700;color:${s.linkColor}">{{ ${k(p.priceKey)} | money }}</div>{{/if}}` : ''}
${p.textKey ? `<div style="margin-top:6px;font-size:14px;line-height:1.5;color:#707070">{{ ${k(p.textKey)} }}</div>` : ''}
${p.buttonLabel && p.urlKey ? `{{#if ${k(p.urlKey)}}}<div style="margin-top:12px">${button(p.buttonLabel, `{{ ${k(p.urlKey)} }}`, p.buttonBg, '#FFFFFF', 999, 14, 'left')}</div>{{/if}}` : ''}
</div></td></tr></table>`;
            const empty = p.emptyText ? `{{else}}<div style="font-size:14px;color:#707070;text-align:center">${escHtml(p.emptyText)}</div>` : '';

            return row(b, ctx, `{{#each ${p.collection || 'items'}}}${card}${empty}{{/each}}`);
        }
        case 'divider':
            return row(b, ctx, `<div style="height:0;border-top:${p.thickness}px solid ${p.color};font-size:0;line-height:0">&nbsp;</div>`);
        case 'spacer':
            return row(b, ctx, `<div style="height:${p.height}px;line-height:${p.height}px;font-size:0">&nbsp;</div>`, { padY: 0, padX: 0 });
        case 'social': {
            const links = [['web', 'Sitio web'], ['instagram', 'Instagram'], ['facebook', 'Facebook'], ['linkedin', 'LinkedIn'], ['youtube', 'YouTube']]
                .filter(([key]) => p[key])
                .map(([key, label]) => `<a href="${attr(p[key])}" target="_blank" style="color:${p.color};text-decoration:none;font-size:13px;font-weight:600;margin:0 8px">${label}</a>`);

            return row(b, ctx, `<div style="text-align:${align3(p.align)}">${links.join('') || '<span style="color:#9A9A9A;font-size:13px">Agrega enlaces a tus redes</span>'}</div>`);
        }
        case 'footer': {
            const parts = [
                p.company ? `<strong>${escHtml(p.company)}</strong>` : '',
                p.address ? escHtml(p.address) : '',
                p.legal ? escHtml(p.legal) : '',
                p.showView ? `<a href="{{ view_url }}" style="color:${p.color};text-decoration:underline">Ver en el navegador</a>` : '',
                p.showUnsubscribe ? `<a href="{{ unsubscribe_url }}" style="color:${p.color};text-decoration:underline">${escHtml(p.unsubscribeText)}</a>` : '',
            ].filter(Boolean);

            return row(b, ctx, `<div style="font-size:12px;line-height:1.7;color:${p.color};text-align:${align3(p.align)}">${parts.join('<br>')}</div>`);
        }
        case 'html':
            return row(b, ctx, p.code ?? '');
    }
}

export function compileDesign(design: Design, opts: { editor?: boolean } = {}): string {
    const s = { ...defaultSettings(), ...design.settings };
    const ctx: Ctx = { s, editor: !!opts.editor };
    const rows = design.blocks.map((b) => compileBlock(b, ctx)).join('\n');

    return `<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<style>
body{margin:0;padding:0;-webkit-text-size-adjust:100%}
img{border:0;outline:none;text-decoration:none}
a{color:${s.linkColor}}
@media only screen and (max-width:620px){.col{display:block!important;max-width:100%!important;width:100%!important;padding:0 0 16px 0!important}.px{padding-left:20px!important;padding-right:20px!important}}
</style>
</head>
<body style="margin:0;padding:0;background-color:${s.bg};font-family:${s.font};color:${s.textColor}">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:${s.bg}"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="${s.width}" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:${s.width}px;background-color:${s.contentBg};border-radius:${s.radius}px;overflow:hidden">
${rows}
</table>
</td></tr></table>
</body>
</html>`;
}

export function emptyDesign(): Design {
    return { settings: defaultSettings(), blocks: [] };
}
