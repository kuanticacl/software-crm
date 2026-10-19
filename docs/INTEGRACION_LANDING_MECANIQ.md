# Integración de www.mecaniq.cl (Nuxt 4) con el CRM

La landing ya existe. Este documento deja lo necesario para conectarla al CRM (https://crm.mecaniq.cl) y trae el **prompt listo
para pegar en el proyecto de la landing**.

## 1. Lo que el CRM deja creado

Al iniciar, el CRM ejecuta `php artisan crm:landing-kit` (idempotente: solo crea lo que falta y no pisa ediciones).
También se puede correr a mano.

| Recurso | Detalle |
|---|---|
| Origen **Landing Top Inmobiliario – Boletín** | Su API key está en *Configuración CRM → Orígenes y API* |
| Origen **Landing Top Inmobiliario – Asesoría** | Su API key está en *Configuración CRM → Orígenes y API* |
| Audiencia **Boletín Top Inmobiliario** | En *Email marketing → Audiencias* |
| Plantilla `bienvenida-boletin` | Gracias por suscribirte + cómo funciona el ranking (marketing) |
| Plantilla `confirmacion-demo` | Gracias por agendar, pronto confirmamos y contactamos (transaccional) |
| Campos del lead | `tipo_solicitud`, `fecha_preferida`, `horario_preferido`, `etapa_proyecto`, `interes` |

Las plantillas se pueden editar en *Email marketing → Plantillas*. Variables: `first_name`, `site_url`
(por defecto https://www.mecaniq.cl), y en la de asesoría `fecha_preferida` y `horario_preferido`.
Requisito: Resend configurado y el worker de colas activo (ya lo está en Docker).

## 2. API

`POST https://crm.mecaniq.cl/api/v1/leads`

Headers: `X-Api-Key: <key del origen>` · `Content-Type: application/json` · `Accept: application/json`

| Campo | Notas |
|---|---|
| `first_name` | Obligatorio (o `name`) |
| `last_name`, `company`, `job_title` | Opcionales |
| `email` / `phone` | Al menos uno |
| `message` | Texto visible en la ficha del lead |
| `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content` | De la URL de la landing |
| `landing_url`, `referrer` | Página de origen |
| `ip`, `user_agent` | **Los del visitante**, no los del servidor de la landing |
| `custom` | Objeto con las claves de campos del lead (arriba) |
| `audience` | Nombre de la audiencia; suscribe el correo (se crea si no existe) |
| `email_template` | Slug de la plantilla a enviar al lead recién creado |
| `email_variables` | Variables extra para la plantilla |

Respuesta `201`:

```json
{ "data": { "id": 12, "source": "landing-top-inmobiliario-boletin", "stage": "Ingreso",
  "email": { "template": "bienvenida-boletin", "status": "queued" },
  "audience": { "name": "Boletín Top Inmobiliario", "status": "subscribed" },
  "created_at": "2026-10-08T18:55:33-03:00" } }
```

Errores: `401` key inválida · `403` origen desactivado · `422` validación (`errors` por campo) · `429` más de 120/min por key.
`audience.status`: `subscribed` | `already_subscribed`. `email.status`: `queued` | `suppressed` (el correo está dado de baja);
si falta la plantilla viene `error`.

### Payload del boletín (origen Boletín)

```json
{ "first_name": "María", "email": "maria@inmobiliaria.cl",
  "message": "Suscripción al boletín Top Inmobiliario",
  "audience": "Boletín Top Inmobiliario",
  "email_template": "bienvenida-boletin",
  "custom": { "interes": "boletin_top_inmobiliario" },
  "utm_source": "linkedin", "landing_url": "https://www.mecaniq.cl/", "ip": "200.1.2.3" }
```

### Payload de agenda tu asesoría (origen Asesoría)

```json
{ "first_name": "María", "last_name": "González", "email": "maria@inmobiliaria.cl", "phone": "+56912345678",
  "company": "Inmobiliaria Demo", "job_title": "Gerente comercial",
  "message": "Quiero agendar una asesoría. Preferencia: 2026-10-15, tarde. Mensaje: quiero subir en el ranking.",
  "email_template": "confirmacion-demo",
  "email_variables": { "fecha_preferida": "15-10-2026", "horario_preferido": "tarde" },
  "custom": { "tipo_solicitud": "asesoria", "fecha_preferida": "2026-10-15", "horario_preferido": "tarde",
              "etapa_proyecto": "en venta", "interes": "asesoria_top_inmobiliario" },
  "utm_source": "linkedin", "landing_url": "https://www.mecaniq.cl/", "ip": "200.1.2.3" }
```

No envíes `audience` en la asesoría salvo que la persona marque un checkbox explícito para recibir el boletín.

## 3. Prompt para el proyecto de la landing (Nuxt 4)

Pega esto tal cual en el proyecto de la landing. Reemplaza nada: las keys se configuran como variables de entorno.

````markdown
# Tarea: conectar los formularios "Boletín" y "Agenda tu asesoría" de esta landing (Nuxt 4) al CRM de MecaniQ

Contexto: el sitio es https://www.mecaniq.cl ("Top Inmobiliarias de Chile", ranking digital de 50 inmobiliarias).
Ya tiene sección de boletín (suscripción gratuita) y debe tener un formulario aparte para agendar una asesoría.
Cada formulario crea un lead en el CRM (https://crm.mecaniq.cl) con su propio origen, y el CRM envía solo el correo de
agradecimiento (las plantillas ya existen en el CRM; no las recrees en la landing).

## 0. Antes de empezar
1. Lee el proyecto: `nuxt.config.ts`, `app/` (o `components/`, `pages/`), `server/`, `package.json`, `.env.example`, el
   formulario de boletín existente y cómo se despliega (Vercel, Cloudflare, Node, Docker…). Resume en 5 líneas lo que encontraste
   antes de escribir código y respeta las convenciones del proyecto (librería de UI, estilos, idioma, manejador de estado).
2. No rediseñes la landing. Respeta la identidad de MECANIQ: Asap, naranja #027BB9, botones tipo píldora, estilo glass de mecaniq.cl.
3. Instala solo lo necesario (`zod` si no está).

## 1. API del CRM
`POST https://crm.mecaniq.cl/api/v1/leads` con headers `X-Api-Key: <key del origen>`, `Content-Type: application/json`, `Accept: application/json`.

Campos: `first_name` (obligatorio), `last_name`, `email`, `phone` (al menos email o phone), `company`, `job_title`, `message`,
`utm_source|medium|campaign|term|content`, `landing_url`, `referrer`, `ip`, `user_agent`, `custom` (objeto), `audience`, `email_template`, `email_variables`.
Respuestas: `201` creado (`data.audience.status` = `subscribed` | `already_subscribed`; `data.email.status` = `queued` | `suppressed`) ·
`401` key inválida · `403` origen desactivado · `422` validación · `429` límite 120/min por key.

### Boletín → key `NUXT_CRM_KEY_NEWSLETTER`
```json
{ "first_name": "...", "email": "...", "message": "Suscripción al boletín Top Inmobiliario",
  "audience": "Boletín Top Inmobiliario", "email_template": "bienvenida-boletin",
  "custom": { "interes": "boletin_top_inmobiliario" } }
```
Si el formulario solo pide correo (sin nombre), usa como `first_name` la parte local del correo (antes de la @) o "Suscriptor".

### Asesoría → key `NUXT_CRM_KEY_ADVISORY`
```json
{ "first_name": "...", "last_name": "...", "email": "...", "phone": "+569XXXXXXXX", "company": "...", "job_title": "...",
  "message": "Quiero agendar una asesoría. Preferencia: AAAA-MM-DD, tarde. Mensaje: <texto libre>",
  "email_template": "confirmacion-demo",
  "email_variables": { "fecha_preferida": "DD-MM-AAAA", "horario_preferido": "tarde" },
  "custom": { "tipo_solicitud": "asesoria", "fecha_preferida": "AAAA-MM-DD", "horario_preferido": "tarde",
              "etapa_proyecto": "...", "interes": "asesoria_top_inmobiliario" } }
```
No envíes `audience` en la asesoría, salvo que la persona marque un checkbox explícito "Quiero recibir el boletín".

## 2. Arquitectura (las keys NUNCA llegan al navegador)
- `nuxt.config.ts`: `runtimeConfig: { crmApiUrl: 'https://crm.mecaniq.cl/api/v1/leads', crmKeyNewsletter: '', crmKeyAdvisory: '' }`
  (privado, sin `public`). Variables: `NUXT_CRM_API_URL`, `NUXT_CRM_KEY_NEWSLETTER`, `NUXT_CRM_KEY_ADVISORY`.
  Agrégalas a `.env.example` sin valores reales y documenta en el README dónde copiar las keys
  (CRM → Configuración CRM → Orígenes y API → "Landing Top Inmobiliario – Boletín / Asesoría"). No commitees keys.
- `server/utils/crm.ts` → `sendLeadToCrm(event, apiKey, payload)`:
  - agrega `ip` (usa antes `cf-connecting-ip` / `x-vercel-forwarded-for` según el proveedor; si no, `getRequestIP(event, { xForwardedFor: true })`)
    y `user_agent` del visitante;
  - `$fetch` con timeout 8000 ms y sin reintentos;
  - 422 del CRM → `createError({ statusCode: 422, data: { errors } })` (errores por campo);
  - 401/403/429/red → `createError({ statusCode: 502, statusMessage: 'No pudimos procesar tu solicitud, intenta de nuevo' })`, y registra el error real
    en el log del servidor sin datos personales.
- `server/api/newsletter.post.ts` y `server/api/asesoria.post.ts`:
  - validan con **zod** (`readValidatedBody`);
  - honeypot (si viene relleno responden `{ ok: true }` sin llamar al CRM);
  - límite por IP (newsletter 5/min, asesoría 3/min) con `useStorage` o un Map con expiración;
  - responden `{ ok: true }` (nunca devuelvas la respuesta cruda del CRM al navegador).
- Validación asesoría: nombre, apellido, correo válido, teléfono chileno (normaliza a `+569XXXXXXXX`), empresa, fecha preferida (hoy o futura,
  máx. 60 días), horario `mañana` | `tarde`, consentimiento de privacidad requerido. Boletín: correo válido + consentimiento.
- `app/composables/useUtm.ts`: en `onMounted` lee `utm_*` de `useRoute().query`, los guarda en `sessionStorage` y los devuelve
  (sin tocar `window` en SSR). Ambos formularios envían los UTM, `landing_url` (`window.location.href`) y `referrer` (`document.referrer`).

## 3. Formularios
- `app/components/NewsletterForm.vue` (reutilizable en hero/footer) y `app/components/AdvisoryForm.vue` (sección o modal "Agenda tu asesoría",
  según como esté la landing). Si ya existe el de boletín, actualízalo en vez de duplicarlo.
- Asesoría en grilla alineada (2 columnas en escritorio, 1 en móvil), campos: nombre, apellido, correo, teléfono, inmobiliaria, cargo (opcional),
  etapa del proyecto (opcional), fecha preferida, horario, mensaje (opcional), consentimiento con enlace a la política de privacidad.
- Estados: cargando (botón deshabilitado + spinner), éxito
  (boletín: "¡Listo! Revisa tu correo, te contamos cómo funciona el ranking" · asesoría: "¡Gracias! Pronto te confirmaremos y contactaremos"),
  errores por campo (desde `error.data.data.errors`) y error genérico. `aria-live="polite"`, foco en el primer error, sin doble envío, labels asociados.
- Si hay GA4 / Meta Pixel: dispara `generate_lead` / `Lead` solo con respuesta OK, con el parámetro `form` = `newsletter` | `advisory`.

## 4. Entregables
1. `server/utils/crm.ts`, `server/api/newsletter.post.ts`, `server/api/asesoria.post.ts`, `app/composables/useUtm.ts`,
   `NewsletterForm.vue`, `AdvisoryForm.vue` (y el cableado en las páginas).
2. `nuxt.config.ts` y `.env.example` actualizados; sección en el README con las 3 variables y cómo probar.
3. Tests del servidor (vitest, si el proyecto lo usa) con `$fetch` mockeado: validación, honeypot, mapeo de errores 422/502 y que la IP/UA se reenvían.

## 5. Verificación obligatoria
Con `npm run dev` y `/?utm_source=test&utm_medium=manual&utm_campaign=top-inmobiliario`:
- Boletín: el lead aparece en el CRM con origen "Landing Top Inmobiliario – Boletín", UTM y `landing_url`; el correo queda en la audiencia
  "Boletín Top Inmobiliario"; llega el correo "¡Gracias por suscribirte…". Un segundo envío con el mismo correo no duplica en la audiencia.
- Asesoría: el lead aparece con origen "…Asesoría", con teléfono, empresa, `message` con la preferencia y los campos `fecha_preferida`, `horario_preferido`,
  `tipo_solicitud`; NO queda en ninguna audiencia (salvo checkbox); llega "Recibimos tu solicitud de asesoría".
- Prueba directa de la API:
```bash
curl -X POST https://crm.mecaniq.cl/api/v1/leads \
  -H "X-Api-Key: $NUXT_CRM_KEY_ADVISORY" -H "Content-Type: application/json" \
  -d '{"first_name":"Prueba","email":"prueba@ejemplo.cl","phone":"+56912345678","company":"Demo","message":"Prueba asesoría","email_template":"confirmacion-demo","custom":{"tipo_solicitud":"asesoria"}}'
```
Al terminar, lista los archivos cambiados y cualquier decisión que hayas tomado distinta a este documento.
````

## 4. Probar el correo desde el CRM

*Email marketing → Plantillas → (plantilla) → Enviar prueba*, o ver el resultado real en *Historial de envíos* tras una suscripción de prueba.
