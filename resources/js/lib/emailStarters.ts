import { BLOCKS, defaultSettings, newBlock } from '@/lib/emailBuilder';
import type { Block, BlockType, Design } from '@/lib/emailBuilder';

const make = (type: BlockType, props: Record<string, any> = {}): Block => {
    const b = newBlock(type);
    b.props = { ...BLOCKS[type].props(), ...props };

    return b;
};

export type Starter = { key: string; name: string; description: string; category: 'marketing' | 'transactional'; subject: string; preheader: string; design: Design };

export const starters: Starter[] = [
    {
        key: 'welcome',
        name: 'Bienvenida a un prospecto (landing)',
        description: 'Se envía cuando alguien se registra en una landing. Usa los datos que llegan por la API.',
        category: 'transactional',
        subject: '{{ first_name | default:"Hola" }}, recibimos tu solicitud',
        preheader: 'Un ejecutivo de Vivienda en Regla te contactará muy pronto.',
        design: {
            settings: defaultSettings(),
            blocks: [
                make('header'),
                make('heading', { text: 'Hola {{ first_name | default:"" }}, ¡gracias por escribirnos!', size: 28 }),
                make('text', { text: 'Recibimos tu solicitud{{#if company}} de **{{ company }}**{{/if}} y ya la estamos revisando. Uno de nuestros ejecutivos te contactará en las próximas horas.\n\nMientras tanto, puedes conocer más de lo que hacemos.' }),
                make('button', { label: 'Conocer nuestros servicios', href: 'https://www.viviendaenregla.cl' }),
                make('spacer', { height: 16 }),
                make('divider'),
                make('footer', { showUnsubscribe: false, legal: 'Recibes este correo porque completaste un formulario en nuestro sitio.' }),
            ],
        },
    },
    {
        key: 'newsletter',
        name: 'Boletín mensual',
        description: 'Novedades con destacado, proyectos dinámicos y llamado a la acción.',
        category: 'marketing',
        subject: 'Novedades de {{ company_name }} · {{ mes | default:"este mes" }}',
        preheader: 'Lo más importante del mes en regularizaciones, planos y diseño.',
        design: {
            settings: defaultSettings(),
            blocks: [
                make('header'),
                make('heading', { text: 'Hola {{ first_name | default:"" }}, esto es lo nuevo', size: 28 }),
                make('text', { text: 'Resumimos lo más relevante del mes para que tomes mejores decisiones sobre tu propiedad.' }),
                make('columns', { title: 'Tu propiedad en regla', text: 'Cómo te ayudamos a regularizar, obtener tus planos y diseñar espacios con orden, criterio y plazos claros.', buttonLabel: 'Leer más' }),
                make('divider'),
                make('heading', { text: 'Proyectos destacados', size: 20 }),
                make('list'),
                make('button', { label: 'Agenda una visita', href: 'https://www.viviendaenregla.cl' }),
                make('social'),
                make('footer', { address: '', showView: true }),
            ],
        },
    },
    {
        key: 'quote',
        name: 'Seguimiento de cotización',
        description: 'Resumen con lista de ítems y botón para responder.',
        category: 'transactional',
        subject: 'Tu propuesta, {{ first_name | default:"" }}',
        preheader: 'Revisa el detalle de tu propuesta.',
        design: {
            settings: defaultSettings(),
            blocks: [
                make('header'),
                make('heading', { text: 'Tu propuesta está lista', size: 26 }),
                make('text', { text: 'Hola {{ first_name }}, te compartimos el resumen de lo conversado. Si quieres ajustar algo, respóndenos directamente a este correo.' }),
                make('list', { collection: 'items', imageKey: '', titleKey: 'nombre', textKey: 'descripcion', priceKey: 'precio', urlKey: '', buttonLabel: '' }),
                make('button', { label: 'Responder propuesta', href: '{{ reply_url | default:"https://www.viviendaenregla.cl" }}' }),
                make('footer', { showUnsubscribe: false }),
            ],
        },
    },
    {
        key: 'blank',
        name: 'En blanco',
        description: 'Parte desde cero y agrega bloques a tu gusto.',
        category: 'marketing',
        subject: '',
        preheader: '',
        design: { settings: defaultSettings(), blocks: [make('header'), make('text'), make('footer')] },
    },
];
