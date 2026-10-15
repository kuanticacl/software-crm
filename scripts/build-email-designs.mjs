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
const heading = (text) => block('heading', { text, size: 26, color: '#393939', padY: 12 });
const text = (t, o = {}) => block('text', { text: t, ...o });
const button = (label, href) => block('button', { label, href, bg: '#FF5300', color: '#FFFFFF', radius: 999, align: 'center', padY: 20 });
const note = (t) => block('text', { text: t, size: 13, color: '#8A8A8A', align: 'center', padY: 6 });
const footer = (transactional) =>
    block('footer', {
        company: '{{ company_name }}',
        address: '{{ADDRESS}}',
        legal: transactional ? 'Recibes este correo porque tienes una cuenta o solicitud en Quiebre.' : 'Recibes este correo porque te suscribiste en top-inmobiliario.quiebre.cl.',
        showUnsubscribe: !transactional,
        showView: false,
    });
const settings = { ...defaultSettings(), bg: '#F4F4F4', contentBg: '#FFFFFF', width: 600, font: 'Arial, Helvetica, sans-serif', textColor: '#393939', linkColor: '#FF5300', radius: 16 };

const templates = {
    'bienvenida-usuario': {
        name: 'Bienvenida de usuario',
        description: 'Se envía al crear un usuario del CRM, con su correo, contraseña inicial y enlace de acceso.',
        category: 'transactional',
        subject: 'Tu acceso al CRM de Quiebre',
        preheader: 'Aquí tienes tus datos para ingresar',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['email', 'Correo de acceso', '', 'maria@quiebre.cl'],
            ['password', 'Contraseña inicial', '', 'Qb-Ejemplo-123'],
            ['role', 'Rol', '', 'Comercial'],
            ['login_url', 'Enlace de acceso', 'https://crm.quiebre.cl/login', 'https://crm.quiebre.cl/login'],
        ],
        blocks: [
            header(),
            heading('¡Bienvenido/a, {{ first_name }}!'),
            text('Te creamos un acceso al CRM de Quiebre{{#if role}} con el rol **{{ role }}**{{/if}}. Estos son tus datos para ingresar:'),
            text('Usuario (correo)\n**{{ email }}**\n\nContraseña inicial\n**{{ password }}**', { bg: '#FAFAFA', padY: 16, size: 16 }),
            button('Ingresar al CRM', '{{ login_url }}'),
            note('Por seguridad, cambia tu contraseña después de ingresar (Configuración → Seguridad). Si no esperabas este correo, ignóralo.'),
            footer(true),
        ],
    },
    'recuperar-password': {
        name: 'Recuperar contraseña',
        description: 'Enlace para restablecer la contraseña (flujo «¿Olvidaste tu contraseña?»).',
        category: 'transactional',
        subject: 'Restablece tu contraseña del CRM de Quiebre',
        preheader: 'Usa este enlace para crear una nueva contraseña',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['reset_url', 'Enlace para restablecer', '', 'https://crm.quiebre.cl/reset-password/token'],
            ['expires_minutes', 'Minutos de vigencia', '60', '60'],
        ],
        blocks: [
            header(),
            heading('Restablece tu contraseña'),
            text('Hola {{ first_name }}, recibimos una solicitud para restablecer la contraseña de tu acceso al CRM de Quiebre.'),
            button('Crear nueva contraseña', '{{ reset_url }}'),
            note('El enlace vence en {{ expires_minutes }} minutos y solo se puede usar una vez.'),
            note('Si no lo solicitaste, ignora este correo: tu contraseña actual sigue funcionando.'),
            footer(true),
        ],
    },
    'bienvenida-top-inmobiliario': {
        name: 'Bienvenida Top Inmobiliario',
        description: 'Agradecimiento a quienes se suscriben al boletín, con una explicación de cómo funciona el ranking.',
        category: 'marketing',
        subject: '¡Gracias por suscribirte al ranking Top Inmobiliarias!',
        preheader: 'Así funciona el ranking y qué recibirás cada semana',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['site_url', 'Enlace a la landing', 'https://top-inmobiliario.quiebre.cl', 'https://top-inmobiliario.quiebre.cl'],
        ],
        blocks: [
            header(),
            heading('¡Gracias por suscribirte, {{ first_name }}!'),
            text('Ya eres parte del boletín de **Top Inmobiliarias de Chile**, el ranking digital de QUIEBRE que mide el desempeño de 50 inmobiliarias del país. Cada semana te contamos qué cambió y qué está pasando en el mercado.'),
            block('heading', { text: 'Cómo funciona el ranking', size: 18, color: '#393939', padY: 8 }),
            text('**1. Evaluamos cinco áreas.** Web (35 pts), Tecnología (25), Redes sociales (15), Visibilidad digital (15) y Branding (15). El puntaje se normaliza a una nota de 1 a 100.\n\n**2. Cada inmobiliaria tiene su ficha.** Ahí ves la nota por criterio, la evidencia pública que la respalda y qué le falta para subir.\n\n**3. Se actualiza cada semana.** Los cambios de posición y los hallazgos llegan primero a tu correo.'),
            note('El ranking mide desempeño digital; no evalúa calidad constructiva, satisfacción de clientes ni solvencia financiera.'),
            button('Ver el ranking', '{{ site_url }}'),
            note('¿Quieres saber cómo subir en el ranking? En la web puedes agendar una asesoría con nuestro equipo.'),
            footer(false),
        ],
    },
    'confirmacion-asesoria': {
        name: 'Confirmación de asesoría',
        description: 'Agradecimiento al agendar una asesoría: pronto se confirmará y contactará al solicitante.',
        category: 'transactional',
        subject: 'Recibimos tu solicitud de asesoría',
        preheader: 'Pronto te confirmaremos y contactaremos',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['site_url', 'Enlace a la landing', 'https://top-inmobiliario.quiebre.cl', 'https://top-inmobiliario.quiebre.cl'],
            ['fecha_preferida', 'Fecha preferida', '', '15-10-2026'],
            ['horario_preferido', 'Horario preferido', '', 'tarde'],
        ],
        blocks: [
            header(),
            heading('Gracias por agendar, {{ first_name }}'),
            text('Recibimos tu solicitud de asesoría. **Pronto te estaremos confirmando** el horario y contactándote para acelerar tu solicitud.'),
            text('{{#if fecha_preferida}}Tu preferencia: **{{ fecha_preferida }}{{#if horario_preferido}} · {{ horario_preferido }}{{/if}}**{{/if}}', { color: '#707070', size: 15 }),
            block('heading', { text: 'Qué sigue', size: 18, color: '#393939', padY: 8 }),
            text('**1.** Un ejecutivo de QUIEBRE revisa tu solicitud.\n\n**2.** Te escribimos o llamamos para confirmar día y hora.\n\n**3.** En la asesoría revisamos el desempeño digital de tu inmobiliaria y las oportunidades para mejorar.'),
            button('Volver al ranking', '{{ site_url }}'),
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
            ['proposal_url', 'Enlace interno', '', 'https://crm.quiebre.cl/proposals/1'],
        ],
        blocks: [
            header(),
            heading('{{ action }}'),
            text('Hola {{ first_name }}, **{{ responder }}** ({{ client_name }}) respondió la propuesta **{{ proposal_number }} · {{ proposal_title }}**.'),
            text('{{#if note}}Comentario del cliente:\n\n«{{ note }}»{{/if}}', { color: '#707070', size: 15 }),
            button('Ver propuesta en el CRM', '{{ proposal_url }}'),
            footer(true),
        ],
    },
    'propuesta-comercial': {
        name: 'Propuesta comercial',
        description: 'Correo con el enlace a la propuesta comercial (se usa al enviar desde Propuestas).',
        category: 'transactional',
        subject: 'Propuesta comercial: {{ proposal_title }}',
        preheader: 'Revisa tu propuesta de Quiebre',
        variables: [
            ['first_name', 'Nombre', '', 'María'],
            ['proposal_title', 'Título de la propuesta', '', 'Marketing digital Proyecto Torre Norte'],
            ['proposal_number', 'Número', '', 'P-2026-0001'],
            ['proposal_url', 'Enlace', '', 'https://www.quiebre.cl'],
            ['valid_until', 'Válida hasta', '', '30 de octubre de 2026'],
            ['message', 'Mensaje', '', 'Adjunto la propuesta que conversamos.'],
        ],
        blocks: [
            header(),
            heading('Hola {{ first_name }}, te compartimos tu propuesta'),
            text('{{ message }}'),
            text('**{{ proposal_title }}**\nN.º {{ proposal_number }}{{#if valid_until}} · válida hasta {{ valid_until }}{{/if}}', { color: '#707070', size: 15 }),
            button('Ver propuesta', '{{ proposal_url }}'),
            note('Desde el enlace puedes revisarla, descargarla en PDF y aceptarla en línea.'),
            footer(true),
        ],
    },
    'alerta-nuevo-cliente': {
        name: 'Alerta de cliente nuevo',
        description: 'Aviso interno al equipo (responsable asignado, responsables del origen y correos por defecto) cuando ingresa un cliente nuevo al CRM.',
        category: 'transactional',
        subject: 'Nuevo cliente: {{ lead_name }}{{#if lead_company}} · {{ lead_company }}{{/if}} ({{ lead_source }})',
        preheader: 'Ingresó desde {{ lead_source }} y quedó asignado a {{ assignee_name }}',
        variables: [
            ['recipient_name', 'Quien recibe el aviso', 'equipo', 'Angelo'],
            ['lead_name', 'Cliente', '', 'Camila Soto'],
            ['lead_email', 'Correo del cliente', '', 'camila@inmobiliaria.cl'],
            ['lead_phone', 'Teléfono', '', '+56 9 1234 5678'],
            ['lead_company', 'Empresa', '', 'Inmobiliaria Sur'],
            ['lead_job_title', 'Cargo', '', 'Gerente comercial'],
            ['lead_message', 'Mensaje', '', 'Quiero conocer sus servicios.'],
            ['lead_source', 'Origen', '', 'Sitio web'],
            ['lead_stage', 'Etapa', '', 'Ingreso'],
            ['assignee_name', 'Asignado a', 'Sin asignar', 'Angelo Rossi'],
            ['lead_created_at', 'Fecha y hora de ingreso', '', '16-10-2026 10:30'],
            ['lead_url', 'Enlace al cliente en el CRM', '', 'https://crm.quiebre.cl/leads/1'],
        ],
        blocks: [
            header(),
            heading('Nuevo cliente: {{ lead_name }}'),
            text('Hola {{ recipient_name }}, ingresó un cliente nuevo desde **{{ lead_source }}** y quedó asignado a **{{ assignee_name }}**.'),
            text('**{{ lead_name }}**{{#if lead_job_title}} · {{ lead_job_title }}{{/if}}{{#if lead_company}}\n{{ lead_company }}{{/if}}\n\nCorreo: {{ lead_email }}{{#if lead_phone}}\nTeléfono: {{ lead_phone }}{{/if}}\nEtapa: {{ lead_stage }} · {{ lead_created_at }}', { bg: '#FAFAFA', padY: 16, size: 15 }),
            text('{{#if lead_message}}Mensaje:\n\n«{{ lead_message }}»{{/if}}', { color: '#707070', size: 15 }),
            button('Ver cliente en el CRM', '{{ lead_url }}'),
            note('Responder rápido marca la diferencia: contáctalo cuanto antes.'),
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
