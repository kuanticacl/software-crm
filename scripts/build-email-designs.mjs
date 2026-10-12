// Genera las plantillas por defecto del CRM con el MISMO modelo de bloques y compilador del editor visual
// (resources/js/lib/emailBuilder.ts), para que se puedan abrir y editar en Plantillas → editor visual.
// Uso: node scripts/build-email-designs.mjs   → escribe resources/email-designs/*.json
import { createJiti } from 'jiti';
import { writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const jiti = createJiti(import.meta.url, { alias: { '@': resolve(root, 'resources/js') } });
const { BLOCKS, defaultSettings, compileDesign } = await jiti.import(resolve(root, 'resources/js/lib/emailBuilder.ts'));

const LOGO = '{{LOGO_URL}}';
let n = 0;
const block = (type, props = {}) => ({ id: `b${(++n).toString(36).padStart(7, '0')}`, type, props: { ...BLOCKS[type].props(), ...props } });
const header = () => block('header', { logoUrl: LOGO, logoWidth: 150, padY: 28 });
const heading = (text) => block('heading', { text, size: 26, color: '#3B2F2F', padY: 12 });
const text = (t, o = {}) => block('text', { text: t, ...o });
const button = (label, href) => block('button', { label, href, bg: '#A9606B', color: '#FFFFFF', radius: 999, align: 'center', padY: 20 });
const note = (t) => block('text', { text: t, size: 13, color: '#9A8C88', align: 'center', padY: 6 });
const footer = (transactional) =>
    block('footer', {
        company: '{{ company_name }}',
        address: '{{ADDRESS}}',
        legal: transactional ? 'Recibes este correo porque tienes una cuenta o solicitud en Vivienda en Regla.' : 'Recibes este correo porque te suscribiste al boletín de Vivienda en Regla.',
        showUnsubscribe: !transactional,
        showView: false,
    });
const settings = { ...defaultSettings(), bg: '#F6EFEB', contentBg: '#FFFFFF', width: 600, font: 'Arial, Helvetica, sans-serif', textColor: '#3B2F2F', linkColor: '#8A4A55', radius: 16 };

const templates = {
    'bienvenida-usuario': {
        name: 'Bienvenida de usuario',
        description: 'Se envía al crear un usuario del CRM, con su correo, contraseña inicial y enlace de acceso.',
        category: 'transactional',
        subject: 'Tu acceso al CRM de Vivienda en Regla',
        preheader: 'Aquí tienes tus datos para ingresar',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['email', 'Correo de acceso', '', 'maria@viviendaenregla.cl'],
            ['password', 'Contraseña inicial', '', 'Qb-Ejemplo-123'],
            ['role', 'Rol', '', 'Comercial'],
            ['login_url', 'Enlace de acceso', 'https://crm.viviendaenregla.cl/login', 'https://crm.viviendaenregla.cl/login'],
        ],
        blocks: [
            header(),
            heading('¡Bienvenido/a, {{ first_name }}!'),
            text('Te creamos un acceso al CRM de Vivienda en Regla{{#if role}} con el rol **{{ role }}**{{/if}}. Estos son tus datos para ingresar:'),
            text('Usuario (correo)\n**{{ email }}**\n\nContraseña inicial\n**{{ password }}**', { bg: '#FBF8F6', padY: 16, size: 16 }),
            button('Ingresar al CRM', '{{ login_url }}'),
            note('Por seguridad, cambia tu contraseña después de ingresar (Configuración → Seguridad). Si no esperabas este correo, ignóralo.'),
            footer(true),
        ],
    },
    'bienvenida-portal': {
        name: 'Bienvenida al portal de clientes',
        description: 'Se envía al crear un acceso al portal de clientes, con correo, contraseña inicial y enlace de ingreso.',
        category: 'transactional',
        subject: 'Tu acceso al portal de clientes de Vivienda en Regla',
        preheader: 'Aquí tienes tus datos para ingresar a tu portal',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['company', 'Empresa', '', 'Oritec SpA'],
            ['email', 'Correo de acceso', '', 'maria@oritec.cl'],
            ['password', 'Contraseña inicial', '', 'Ec-Ejemplo-123'],
            ['portal_url', 'Enlace al portal', 'https://clientes.viviendaenregla.cl/login', 'https://clientes.viviendaenregla.cl/login'],
        ],
        blocks: [
            header(),
            heading('¡Bienvenido/a a tu portal, {{ first_name }}!'),
            text('Creamos tu acceso al **portal de clientes de Vivienda en Regla**{{#if company}} para **{{ company }}**{{/if}}. Ahí puedes revisar tus propuestas, tus proyectos y tus cobros y boletas, pagados o por pagar.'),
            text('Usuario (correo)\n**{{ email }}**\n\nContraseña inicial\n**{{ password }}**', { bg: '#FBF8F6', padY: 16, size: 16 }),
            button('Ingresar a mi portal', '{{ portal_url }}'),
            note('Por seguridad, te pediremos cambiar la contraseña la primera vez que ingreses. Si no esperabas este correo, ignóralo.'),
            footer(true),
        ],
    },
    'cobro-factura': {
        name: 'Cobro: nuevo cobro disponible',
        description: 'Aviso al cliente cuando se le envía un cobro con su boleta de honorarios (botón «Enviar cobro»).',
        category: 'transactional',
        subject: '{{ subject_line }}',
        preheader: 'Tu cobro ya está disponible en el portal',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['company', 'Empresa', '', 'Oritec SpA'],
            ['subject_line', 'Asunto', '', 'Nuevo cobro de Vivienda en Regla · boleta de honorarios 12'],
            ['document', 'Documento', '', 'Boleta de honorarios'],
            ['invoice_number', 'N° de documento', '', '12'],
            ['concept', 'Concepto', '', 'Regularización vivienda · 50% de anticipo'],
            ['total', 'Total a pagar', '', '$325.000'],
            ['amount_detail', 'Detalle de la retención', '', 'Honorarios $383.481 − retención 15,25% ($58.481)'],
            ['due_date', 'Vencimiento', '', '15-11-2026'],
            ['payment_link', 'Enlace de pago', '', ''],
            ['bank_details', 'Datos para transferencia', '', '<strong>Banco Estado</strong> · Cuenta corriente<br>N° 123456789'],
            ['portal_url', 'Enlace al portal', 'https://clientes.viviendaenregla.cl/portal/facturas', 'https://clientes.viviendaenregla.cl/portal/facturas'],
        ],
        blocks: [
            header(),
            heading('Tienes un nuevo cobro, {{ first_name }}'),
            text('Emitimos la **{{ document }}** N° **{{ invoice_number }}**{{#if company}} a nombre de **{{ company }}**{{/if}}. Puedes verla y descargar el PDF en tu portal.'),
            text('Concepto\n**{{ concept }}**\n\nTotal a pagar\n**{{ total }}**\n{{#if amount_detail}}{{ amount_detail }}\n{{/if}}\nVence el\n**{{ due_date }}**', { bg: '#FBF8F6', padY: 16, size: 16 }),
            button('Ver mi cobro', '{{ portal_url }}'),
            text('{{#if payment_link}}También puedes pagar directamente aquí: {{ payment_link }}{{/if}}', { size: 14, color: '#7A6A68', align: 'center', padY: 4 }),
            text('{{#if bank_details}}**Pago por transferencia**\n\n{{{ bank_details }}}{{/if}}', { bg: '#FBF8F6', padY: 14, size: 14 }),
            note('Si ya realizaste el pago, puedes ignorar este correo.'),
            footer(true),
        ],
    },
    'recordatorio-pago': {
        name: 'Recordatorio de pago',
        description: 'Recordatorio automático (antes, el día y después del vencimiento) o manual de un cobro por pagar.',
        category: 'transactional',
        subject: '{{ subject_line }}',
        preheader: '{{ headline }}',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['subject_line', 'Asunto', '', 'Recordatorio: tu boleta de honorarios 12 vence pronto'],
            ['headline', 'Título', '', 'Tu cobro vence en 5 días'],
            ['message', 'Mensaje', '', 'Te recordamos que la boleta de honorarios N° 12 vence el 15-11-2026.'],
            ['invoice_number', 'N° de documento', '', '12'],
            ['concept', 'Concepto', '', 'Regularización vivienda · 50% de anticipo'],
            ['total', 'Total a pagar', '', '$325.000'],
            ['amount_detail', 'Detalle de la retención', '', 'Honorarios $383.481 − retención 15,25% ($58.481)'],
            ['due_date', 'Vencimiento', '', '15-11-2026'],
            ['payment_link', 'Enlace de pago', '', ''],
            ['bank_details', 'Datos para transferencia', '', '<strong>Banco Estado</strong> · Cuenta corriente<br>N° 123456789'],
            ['portal_url', 'Enlace al portal', 'https://clientes.viviendaenregla.cl/portal/facturas', 'https://clientes.viviendaenregla.cl/portal/facturas'],
        ],
        blocks: [
            header(),
            heading('{{ headline }}'),
            text('Hola {{ first_name }}, {{ message }}'),
            text('Documento\n**N° {{ invoice_number }}** · {{ concept }}\n\nTotal a pagar\n**{{ total }}**\n{{#if amount_detail}}{{ amount_detail }}\n{{/if}}\nVencimiento\n**{{ due_date }}**', { bg: '#FBF8F6', padY: 16, size: 16 }),
            button('Ver y pagar mi cobro', '{{ portal_url }}'),
            text('{{#if payment_link}}Enlace de pago directo: {{ payment_link }}{{/if}}', { size: 14, color: '#7A6A68', align: 'center', padY: 4 }),
            text('{{#if bank_details}}**Pago por transferencia**\n\n{{{ bank_details }}}{{/if}}', { bg: '#FBF8F6', padY: 14, size: 14 }),
            note('Si ya realizaste el pago, avísanos respondiendo este correo para actualizar tu estado.'),
            footer(true),
        ],
    },
    'recuperar-password': {
        name: 'Recuperar contraseña',
        description: 'Enlace para restablecer la contraseña (flujo «¿Olvidaste tu contraseña?»).',
        category: 'transactional',
        subject: 'Restablece tu contraseña del CRM de Vivienda en Regla',
        preheader: 'Usa este enlace para crear una nueva contraseña',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['reset_url', 'Enlace para restablecer', '', 'https://crm.viviendaenregla.cl/reset-password/token'],
            ['expires_minutes', 'Minutos de vigencia', '60', '60'],
        ],
        blocks: [
            header(),
            heading('Restablece tu contraseña'),
            text('Hola {{ first_name }}, recibimos una solicitud para restablecer la contraseña de tu acceso al CRM de Vivienda en Regla.'),
            button('Crear nueva contraseña', '{{ reset_url }}'),
            note('El enlace vence en {{ expires_minutes }} minutos y solo se puede usar una vez.'),
            note('Si no lo solicitaste, ignora este correo: tu contraseña actual sigue funcionando.'),
            footer(true),
        ],
    },
    'gracias-contacto': {
        name: 'Gracias por contactarnos',
        description: 'Respuesta automática al formulario de contacto de viviendaenregla.cl.',
        category: 'transactional',
        subject: 'Recibimos tu mensaje, {{ first_name }}',
        preheader: 'Te responderemos a la brevedad',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['site_url', 'Sitio web', 'https://www.viviendaenregla.cl', 'https://www.viviendaenregla.cl'],
        ],
        blocks: [
            header(),
            heading('¡Gracias por escribirnos, {{ first_name }}!'),
            text('Recibimos tu mensaje y ya lo está revisando nuestro equipo. **Te responderemos a la brevedad** para conversar sobre tu propiedad o tu espacio.'),
            block('heading', { text: 'Cómo trabajamos', size: 18, color: '#3B2F2F', padY: 8 }),
            text('**1. Conversamos.** Entendemos tu propiedad o tu espacio, tus plazos y lo que necesitas lograr.\n\n**2. Proponemos.** Te enviamos una propuesta clara, con alcance, etapas y honorarios.\n\n**3. Resolvemos.** Hacemos los planos, los trámites o el diseño, y te acompañamos hasta la entrega.'),
            button('Conocer Vivienda en Regla', '{{ site_url }}'),
            note('Arquitectura: regularizaciones, planos y diseño de interiores para viviendas, oficinas, locales comerciales y stands.'),
            footer(true),
        ],
    },
    'gracias-cotizacion': {
        name: 'Recibimos tu solicitud de cotización',
        description: 'Confirmación al solicitar una cotización o reunión: pronto se confirmará y contactará al solicitante.',
        category: 'transactional',
        subject: 'Recibimos tu solicitud de cotización',
        preheader: 'Pronto te confirmaremos y contactaremos',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['site_url', 'Sitio web', 'https://www.viviendaenregla.cl', 'https://www.viviendaenregla.cl'],
            ['tipo_servicio', 'Servicio de interés', '', 'Regularización'],
            ['fecha_preferida', 'Fecha preferida', '', '15-10-2026'],
        ],
        blocks: [
            header(),
            heading('Gracias por tu solicitud, {{ first_name }}'),
            text('Recibimos tu solicitud de cotización. **Pronto te estaremos confirmando** y contactando para acelerar el proceso.'),
            text('{{#if tipo_servicio}}Servicio de interés: **{{ tipo_servicio }}**{{/if}}{{#if fecha_preferida}}\nVisita o reunión preferida: **{{ fecha_preferida }}**{{/if}}', { color: '#7A6A68', size: 15 }),
            block('heading', { text: 'Qué sigue', size: 18, color: '#3B2F2F', padY: 8 }),
            text('**1.** El equipo de Vivienda en Regla revisa tu solicitud.\n\n**2.** Te escribimos o llamamos para coordinar la visita o reunión.\n\n**3.** Preparamos una propuesta a la medida de tu propiedad o tu espacio.'),
            button('Conoce Vivienda en Regla', '{{ site_url }}'),
            footer(true),
        ],
    },
    'propuesta-respondida': {
        name: 'Propuesta respondida por el cliente',
        description: 'Aviso interno al responsable cuando el cliente firma, solicita ajustes o rechaza una propuesta.',
        category: 'transactional',
        subject: '{{ action }}: {{ proposal_number }} · {{ client_name }}',
        preheader: '{{ responder }} respondió la propuesta',
        variables: [
            ['first_name', 'Responsable', '', 'Esteban'],
            ['action', 'Respuesta', '', 'Propuesta firmada y aceptada'],
            ['client_name', 'Cliente', '', 'Oritec SpA'],
            ['responder', 'Quién respondió', '', 'Cristian Suarez'],
            ['proposal_title', 'Título', '', 'Especialista en IA'],
            ['proposal_number', 'Número', '', 'P-2026-0001'],
            ['note', 'Comentario del cliente', '', 'Necesitamos ajustar las fechas.'],
            ['proposal_url', 'Enlace interno', '', 'https://crm.viviendaenregla.cl/proposals/1'],
        ],
        blocks: [
            header(),
            heading('{{ action }}'),
            text('Hola {{ first_name }}, **{{ responder }}** ({{ client_name }}) respondió la propuesta **{{ proposal_number }} · {{ proposal_title }}**.'),
            text('{{#if note}}Comentario del cliente:\n\n«{{ note }}»{{/if}}', { color: '#7A6A68', size: 15 }),
            button('Ver propuesta en el CRM', '{{ proposal_url }}'),
            footer(true),
        ],
    },
    'propuesta-comercial': {
        name: 'Propuesta comercial',
        description: 'Correo con el enlace a la propuesta comercial (se usa al enviar desde Propuestas).',
        category: 'transactional',
        subject: 'Propuesta comercial: {{ proposal_title }}',
        preheader: 'Revisa tu propuesta de Vivienda en Regla',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['proposal_title', 'Título de la propuesta', '', 'Marketing digital Proyecto Torre Norte'],
            ['proposal_number', 'Número', '', 'P-2026-0001'],
            ['proposal_url', 'Enlace', '', 'https://www.viviendaenregla.cl'],
            ['valid_until', 'Válida hasta', '', '30 de octubre de 2026'],
            ['message', 'Mensaje', '', 'Adjunto la propuesta que conversamos.'],
        ],
        blocks: [
            header(),
            heading('Hola {{ first_name }}, te compartimos tu propuesta'),
            text('{{ message }}'),
            text('**{{ proposal_title }}**\nN.º {{ proposal_number }}{{#if valid_until}} · válida hasta {{ valid_until }}{{/if}}', { color: '#7A6A68', size: 15 }),
            button('Ver propuesta', '{{ proposal_url }}'),
            note('Desde el enlace puedes revisarla, descargarla en PDF y aceptarla en línea.'),
            footer(true),
        ],
    },
};

for (const [slug, t] of Object.entries(templates)) {
    const design = { settings, blocks: t.blocks };
    const out = {
        slug,
        name: t.name,
        description: t.description,
        category: t.category,
        subject: t.subject,
        preheader: t.preheader,
        variables: t.variables.map(([key, label, def, sample]) => ({ key, label, default: def, sample })),
        design,
        html: compileDesign(design),
    };
    writeFileSync(resolve(root, `resources/email-designs/${slug}.json`), JSON.stringify(out, null, 2) + '\n');
    console.log('✓', slug, `${out.html.length} bytes`);
}
