# CRM Suite

Plataforma web para **gestionar el ciclo comercial completo** de una empresa de servicios: captación de prospectos, seguimiento en un tablero Kanban, propuestas comerciales con firma del cliente, email marketing, un asistente de IA que opera el sistema y un **portal de clientes** con servicios contratados, facturas y cobranza automática.

**Stack:** Laravel 13 · PHP 8.3+ · Inertia 3 · Vue 3 (TypeScript) · Tailwind CSS 4 · shadcn-vue · MariaDB / SQLite · Redis · Docker.

---

## Contenido

1. [Qué incluye](#1-qué-incluye)
2. [Primeros pasos](#2-primeros-pasos)
3. [Configuración](#3-configuración)
4. [Roles y permisos](#4-roles-y-permisos)
5. [Módulos](#5-módulos)
   - [Prospectos, clientes y Kanban](#51-prospectos-clientes-y-kanban) · [Propuestas comerciales](#52-propuestas-comerciales) · [Email marketing](#53-email-marketing) · [Inteligencia artificial y Agent](#54-inteligencia-artificial-y-agent) · [Portal de clientes y cobranza](#55-portal-de-clientes-y-cobranza)
6. [API](#6-api)
7. [Tareas programadas y colas](#7-tareas-programadas-y-colas)
8. [Despliegue](#8-despliegue)
9. [Personalización de marca](#9-personalización-de-marca)
10. [Rendimiento](#10-rendimiento)
11. [Estructura del proyecto](#11-estructura-del-proyecto) · [Comandos útiles](#12-comandos-útiles)

---

## 1. Qué incluye

| Área | Capacidades |
| --- | --- |
| **Comercial** | Dashboard, **Kanban** con arrastre y panel lateral, ficha de cliente (persona) y de **empresa**, orígenes con API key, etapas configurables, campos personalizados, puntaje de prospectos (0–100). |
| **Propuestas** | Constructor con catálogo de servicios, tarifas en **UF o CLP**, IVA, descuentos, versiones, **PDF**, enlace público con **firma del cliente** (aceptar, rechazar o pedir ajustes). |
| **Email marketing** | Editor visual de plantillas, boletines con audiencias, automatizaciones, API de envío, tracking y bajas sobre **Resend**. |
| **Inteligencia artificial** | Proveedores intercambiables (OpenAI, Claude, Gemini, Groq, Mistral, DeepSeek, xAI, OpenRouter o compatible OpenAI), análisis de prospectos, redacción de propuestas y correos, lectura de facturas en PDF, monitor de consumo y costos. |
| **Agent** | Chat flotante que opera el sistema en lenguaje natural (crear, buscar, editar y eliminar **con tu confirmación**), con archivos adjuntos y voz. |
| **Portal de clientes y cobranza** | Portal propio para cada cliente (propuestas, servicios, facturas, datos de pago), servicios contratados con renovación, facturación con PDF externo, recordatorios automáticos y dashboards de cuentas. |
| **Administración** | Usuarios, roles y permisos granulares, papelera con restauración, historial de actividad, limpieza de datos de prueba, monitor de rendimiento. |

---

## 2. Primeros pasos

**Requisitos:** PHP ≥ 8.3 (8.4 en Docker), Composer, Node ≥ 22. Para leer PDFs en el Agent y en la lectura de facturas se usa `pdftotext` (poppler; ya incluido en la imagen Docker).

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed        # roles, etapas, orígenes y usuario administrador
php artisan storage:link
composer run dev                  # servidor + worker de cola + Vite → http://localhost:8000
```

Usuario administrador inicial: define `ADMIN_EMAIL`, `ADMIN_NAME` y `ADMIN_PASSWORD` en el `.env`. En entornos no productivos la contraseña por defecto es `password`; **en producción `ADMIN_PASSWORD` es obligatoria**.

Datos de demostración opcionales:

```bash
php artisan crm:seed-demo          # una empresa con su cliente y propuestas por cada origen (idempotente)
php artisan db:seed --class=DemoSeeder
```

---

## 3. Configuración

Las variables principales (ver `.env.example` y `.env.dokploy.example`):

| Variable | Descripción |
| --- | --- |
| `APP_URL` | URL pública. La usan los enlaces de seguimiento, bajas, webhooks y correos. |
| `DB_*` | Base de datos (SQLite en local; MariaDB/MySQL en producción). |
| `REDIS_*`, `SESSION_DRIVER`, `CACHE_STORE` | Sesiones y caché en Redis (recomendado en producción). |
| `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD` | Administrador inicial (se crea solo si el sistema está vacío). |
| `RESEND_API_KEY` | Opcional: también se configura desde la interfaz. Sin clave el sistema funciona en **modo prueba** (registra los correos sin enviarlos). |
| `PORTAL_DOMAIN`, `PORTAL_URL` | Dominio del portal de clientes (p. ej. `clientes.tudominio.com`). Vacío = el portal queda en `/portal`. |
| `BILLING_CHARGE_AHEAD_DAYS`, `BILLING_TAX_RATE` | Anticipación con que se generan los cobros recurrentes (10) e IVA por defecto (19). |
| `MCP_ALLOW_PRIVATE` | Solo desarrollo: permite servidores MCP en localhost/redes privadas. En producción, `false` (por defecto). |
| `SEED_DEMO` | En Docker, carga datos de demostración al iniciar. |
| `TRUSTED_PROXIES`, `PERF_HEADERS` | Proxy inverso y cabeceras de diagnóstico de rendimiento. |

Desde la interfaz se configuran además: datos de la empresa (firma y pie de propuestas), remitente y dominio de correo, proveedores de IA, orígenes, etapas, campos personalizados y datos bancarios.

---

## 4. Roles y permisos

Los permisos viven en [`config/permissions.php`](config/permissions.php), agrupados (`leads.view`, `proposals.send`, `billing.manage`…). Agregar uno nuevo basta con sumarlo ahí: aparece solo en la matriz de roles y queda disponible como:

```php
Route::get(...)->middleware('can:clients.create');   // rutas
$user->hasPermission('leads.assign');                // código
```
```ts
const { can } = usePermissions();                    // Vue
can('leads.delete')
```

- El rol **Administrador** (`admin`) siempre tiene todos los permisos y no se puede editar ni eliminar. Hay roles base (Ejecutivo comercial, Supervisor, Solo lectura) editables.
- `leads.view` muestra solo los prospectos **asignados**; `leads.view_all` muestra todos.
- Grupos relevantes: `leads.*`, `clients.*`, `proposals.*`, `services.*`, `templates.*`, `campaigns.*`, `contracts.*` (incluye `contracts.costs`, información interna), `billing.*`, `portal.manage`, `ai.*`, `agent.use`, `trash.manage`, `demo.purge`.
- Los accesos del **portal de clientes** usan un rol de sistema sin permisos del CRM y están aislados del sistema interno por middleware.

---

## 5. Módulos

### 5.1 Prospectos, clientes y Kanban

- **Terminología:** *cliente* = la persona o contacto comercial que se gestiona en el Kanban; *empresa* = la organización (RUT, razón social, giro, dirección y contacto). En código y API se llaman `lead` y `client`.
- **Kanban:** arrastre entre etapas, panel lateral sin salir del tablero, selección múltiple con acciones masivas, filtros avanzados, columnas colapsables, creación rápida, motivo al descartar y valor al concretar, alertas de inactividad y seguimiento vencido.
- **Valor estimado en CLP o UF:** se guarda el monto original y su equivalente en pesos (UF del día) para sumar y ordenar.
- **Ficha:** datos de contacto, UTM y metadatos de captura, notas (con opción privada), asignación, historial, propuestas asociadas y campos personalizados.
- **Orígenes:** cada origen (formulario, landing, campaña…) tiene su **API key**; el prospecto se atribuye por la clave con que llega.
- **Puntaje y perfilamiento (0–100):** determinista y explicable (perfil y contacto, intención y origen, avance comercial, dinamismo). Se recalcula con cada dato nuevo y cada noche; temperatura A/B/C/D, filtro y orden en el Kanban.
- **Papelera:** eliminar envía a la papelera con sus datos relacionados y se puede restaurar. Al borrar un origen o empresa se informa lo que arrastra y se puede **transferir** a otro registro.

### 5.2 Propuestas comerciales

- **Constructor:** servicios del catálogo (ajustables por propuesta) y servicios únicos, duración en meses, descuento (% o monto), IVA, vigencia y secciones editables (resumen, objetivos, alcance, plan, condiciones). Los totales siempre los calcula el servidor.
- **UF:** las propuestas se arman por defecto en UF (se puede elegir CLP). El valor viene de [findic.cl](https://findic.cl/api/uf), con historial en base de datos; **al enviar la propuesta la UF queda congelada** y el documento indica la UF de referencia.
- **Documento y PDF:** el mismo documento se usa en la vista previa, la ficha, el enlace público y el PDF generado en el servidor (dompdf, sin navegador).
- **Enlace público** `/p/{token}` (sin sesión, `noindex`): registra vistas y permite al cliente **firmar y aceptar** (firma dibujada, nombre y RUT), **rechazar** o **solicitar ajustes**, dejando IP, fecha y comentarios. Cada hito queda en el historial y se avisa al responsable por correo.
- **Versiones y Kanban:** varias propuestas por prospecto, «Nueva versión» duplica como borrador; las etapas pueden **requerir propuesta** y el sistema ofrece crearla al mover la tarjeta. Se puede asociar una propuesta existente a un prospecto.
- **Texto enriquecido:** editor TipTap con HTML sanitizado por lista blanca.
- **Catálogo** (*Catálogo*): servicios pre armados con categoría, modalidad (pago único o mensual), tarifa neta, descripción y entregables.

### 5.3 Email marketing

Todo vive bajo **Email marketing**: Boletines, Plantillas, Audiencias, Automatizaciones, Historial de envíos, Bajas y rebotes, API e integraciones y Configuración.

1. **Conectar Resend:** crea una API key y verifica tu dominio en [resend.com](https://resend.com); en *Configuración de email* pega la clave, define remitente, nombre de empresa y dirección postal, y pulsa **Enviar prueba**.
2. **Plantillas:** editor **visual por bloques** (encabezado, título, texto, imagen, botón, imagen + texto, lista dinámica, divisor, redes, pie, HTML libre) y editor HTML con vista previa escritorio/móvil, envío de prueba y ejemplos de API. HTML compatible con Gmail/Outlook.
3. **Variables y sintaxis:**
   ```
   {{ first_name }}                         variable (escapada)
   {{ first_name | default:"estimado/a" }}  valor por defecto
   {{ monto | money }}  {{ fecha | date:"d/m/Y" }}  {{ x | upper }} (lower, capitalize, truncate:40, url, nl2br)
   {{{ html_crudo }}}                       sin escapar
   {{#if empresa}} … {{else}} … {{/if}}     condicional ({{#unless x}})
   {{#each items}}{{ this.nombre }}{{/each}}  bucle
   ```
   Variables del sistema: `unsubscribe_url`, `view_url`, `to_name`, `to_email`, `current_year`, `company_name`.
4. **Boletines y audiencias:** listas manuales (pegar desde Excel/CSV) combinadas con prospectos y empresas del CRM filtrables; deduplicación y exclusión de bajas, rebotes y spam; conteo en vivo, envío inmediato o programado, por lotes con pausa/reanudar/cancelar y embudo de resultados (enviados → entregados → abiertos → clics).
5. **Automatizaciones:** reglas sin código que envían una plantilla cuando ingresa un prospecto o cambia de etapa, con condiciones por origen/etapa, retraso y destino (correo del prospecto o aviso interno).
6. **Seguimiento y bajas:** aperturas (pixel) y clics (redirección firmada); enlace de baja con *one-click* (`List-Unsubscribe-Post`); bajas, rebotes permanentes y reclamos de spam se excluyen de todo envío futuro. **Webhook de Resend** en `https://TU-DOMINIO/webhooks/resend` (firma Svix; pega el *signing secret* en la configuración).
7. **Plantillas del sistema** (editables): bienvenida de usuario, recuperación de contraseña, respuestas a formularios, propuesta comercial, propuesta respondida, bienvenida al portal, cobro de factura y recordatorio de pago. Se definen en `resources/email-designs/*.json` (generadas con `node scripts/build-email-designs.mjs`); si las modificas en el sistema, no se sobrescriben.

### 5.4 Inteligencia artificial y Agent

Integrada con el SDK oficial `laravel/ai`. Todo es opcional: sin proveedor el sistema funciona igual.

- **Proveedores** (*Inteligencia artificial → Proveedores*): claves **cifradas** en base de datos (nunca llegan al navegador ni a `.env`), prueba de conexión, uno **por defecto** y modelo de transcripción configurable. Cada llamada queda registrada en `ai_runs`.
- **Asistentes:** redacción de propuestas y de correos (el diseño lo impone el sistema), descripción y sugerencia de servicios, análisis por prospecto (resumen, señales, riesgos, próximos pasos, mensaje sugerido) y **lectura de facturas en PDF** que precarga folio, fechas, neto/IVA, concepto y empresa para que los revises.
- **Privacidad:** por defecto no se envían nombre, correo, teléfono ni notas al proveedor; la lectura de facturas y el Agent sí envían los datos necesarios para su función.
- **Monitor de consumo y gastos:** llamadas, tokens, costo estimado (precio por millón de tokens, editable por modelo), fallos, gráficos diarios, desglose por modelo/función/usuario y **presupuesto mensual** con proyección.
- **Agent (chat flotante, `agent.use`):**
  - Opera el sistema en lenguaje natural: buscar, crear (empresas con RUT validado, prospectos, propuestas, servicios, cobros, accesos al portal), registrar seguimientos, mover de etapa, consultar estados y resumir la cobranza.
  - **Editar y eliminar siempre con tu confirmación:** el chat muestra un recuadro «antes → después» con **Confirmar / Cancelar**; solo al confirmar se aplica en el servidor con tus permisos (la acción vence a los 30 minutos y es de un solo uso). Casi todo va a la papelera.
  - Cada herramienta actúa **como el usuario** (mismos permisos y visibilidad). Ofrece **archivos adjuntos** (PDF, Word, Excel, TXT, CSV, MD, JSON; hasta 5 de 10 MB), **voz** (transcripción en servidor o dictado del navegador) y **modo foco** a pantalla centrada.
  - Los usuarios del portal de clientes no tienen acceso al Agent.
- **Herramientas MCP (*Inteligencia artificial → Herramientas MCP*, `mcp.manage`):** conecta servidores [MCP](https://modelcontextprotocol.io) remotos (transporte *Streamable HTTP*) para darle al Agent herramientas externas.
  - **Autenticación:** sin credenciales, **API key** (encabezado y prefijo configurables, p. ej. `Authorization: Bearer …` o `X-API-Key`) u **OAuth 2.1** (descubrimiento automático, registro dinámico de cliente o Client ID manual, PKCE S256, renovación automática del token). La URL de retorno OAuth es `/ai/mcp/oauth/callback`.
  - **Seguridad:** claves y tokens **cifrados** y jamás enviados al navegador; solo `https://` público (bloquea localhost, redes privadas y metadatos de nube, fija la IP resuelta contra *DNS rebinding*, no sigue redirecciones); respuestas acotadas; límite de 40 llamadas/min por usuario; **registro de cada llamada** (parámetros sensibles ocultos).
  - **Control fino:** cada herramienta se habilita por separado (al descubrir, solo las marcadas como *solo lectura* quedan activas) y las que modifican datos piden **confirmación con botón** en el chat. El Agent las usa únicamente si el rol tiene `mcp.use`.
  - **Contenido no confiable:** la salida de un servidor externo llega al modelo delimitada y marcada como dato, no como instrucciones.
  - `MCP_ALLOW_PRIVATE=true` solo para desarrollo (permite servidores locales). Mantenerlo en `false` en producción.

### 5.5 Portal de clientes y cobranza

- **Portal** (`PORTAL_DOMAIN` o `/portal`): cada contacto de una empresa ve sus **propuestas, servicios contratados, facturas** (por pagar y pagadas, con descarga del PDF), datos de transferencia y su cuenta. Responsive e idéntico en identidad al resto del sistema.
- **Accesos:** desde la ficha de la empresa se crean uno o varios accesos; se genera una contraseña y se envía un correo de bienvenida con los datos de ingreso (el cliente debe cambiarla al entrar). Se pueden reenviar, desactivar o eliminar.
- **Ver como cliente:** vista previa de solo lectura del portal tal como lo ve un cliente (con barra para salir).
- **Servicios contratados** (*Cobranza → Servicios*): empresa, propuesta asociada, valor neto (CLP/UF), ciclo (único, mensual, trimestral, anual), inicio y término, **renovación automática**, día de cobro, enlace de pago y **servicios asociados** (p. ej. hosting y dominio de un sitio web). Estados: Activo, Pendiente de pago, Bloqueado y Cancelado; «Por iniciar» y «Finalizado» se deducen de las fechas.
- **Costos y gastos** por servicio y margen (`contracts.costs`): **solo uso interno**; el portal se arma con campos explícitos y nunca los incluye.
- **Facturación** (*Cobranza → Facturación*): la factura la emite tu software externo; aquí se crea el cobro, se **adjunta el PDF** (almacenamiento privado), se emite, se envía y se marca pagada (con medio y referencia). Se pueden registrar **facturas antiguas ya pagadas** sin generar avisos. Estados: por emitir → por pagar → pagada / anulada; «vencida» se calcula.
- **Cobranza automática:** los servicios recurrentes generan solos el cobro de cada período y envían **recordatorios antes, el día y después del vencimiento** (configurables globalmente y por servicio); los cobros únicos se envían con el botón *Enviar cobro*. Los recordatorios se detienen al marcar el pago.
- **Datos bancarios:** hasta 5 cuentas para transferencias, visibles en el portal (con botón copiar) y en los correos de cobro.
- **Dashboard y Kanban de cuentas:** ingreso recurrente mensual y anual, cuentas activas, por cobrar, vencido, cobrado, facturado vs cobrado, antigüedad de la deuda, renovaciones y rentabilidad; el Kanban de **cuentas** clasifica cada empresa por su situación (En mora, Por facturar, Por cobrar, Renovación próxima, Al día…) y el de **cobros** permite arrastrar una factura a «Pagadas» o a «Por pagar».

### 5.6 Variante para estudios de arquitectura y servicios profesionales (rama `viviendaenregla`)

Esta rama adapta el CRM para un estudio de arquitectura que cobra **honorarios** (no recurrentes) y atiende tanto a **personas** como a **empresas**:

- **Prospectos y clientes:** el Kanban trabaja con *prospectos*; un *cliente* puede ser **persona** o **empresa** (RUT validado; la razón social y el giro solo aplican a empresas).
- **Proyectos:** servicios de pago único con **modalidad** «solo honorarios» u «honorarios + obra completa», dirección del proyecto, presupuesto de obra (informativo) y **plan de pagos** por hitos (50/50, 40/30/30 o a medida) que genera cobros «por emitir».
- **Boleta de honorarios:** los cobros son por defecto **boletas de honorarios** (sin IVA). La **retención** se calcula con la tasa vigente (Ley 21.133: 2026 = 15,25 %, 2027 = 16 %, 2028 = 17 %; editable por cobro o con el ajuste `billing.retention_rate`). Si el cliente es una empresa, la retiene y paga el **líquido**; si es una persona, paga el bruto y la retención queda informada. También admite factura (con IVA) y «otro documento» para cobros de obra.
- **Lectura de documentos con IA:** precarga boletas y facturas en PDF (folio, receptor, bruto, retención y líquido).
- **Marca:** paleta oro rosa, tipografías Jost y Cormorant Garamond; el catálogo inicial incluye regularizaciones, planos, diseño de interiores y gestión de obra (valores referenciales editables en *Catálogo*).

---

## 6. API

Dos APIs REST bajo `https://TU-DOMINIO/api/v1`, con límites de uso y respuestas JSON.

### Ingreso de prospectos — `POST /api/v1/leads`

Autenticación con la **API key del origen** (`X-Api-Key`).

```bash
curl -X POST https://TU-DOMINIO/api/v1/leads \
  -H "X-Api-Key: TU_API_KEY_DE_ORIGEN" -H "Content-Type: application/json" \
  -d '{
    "first_name": "María", "last_name": "González",
    "email": "maria@ejemplo.com", "phone": "+56912345678",
    "company": "Empresa Ejemplo", "job_title": "Gerente comercial",
    "message": "Quiero cotizar un proyecto",
    "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "campana-otono",
    "landing_url": "https://tusitio.com/contacto",
    "custom": { "proyecto_de_interes": "Sitio web" },
    "email_template": "bienvenida"
  }'
```

- Requerido: `first_name` (o `name`) y al menos `email` o `phone`. Se aceptan `utm_*`, `ip`, `user_agent`, `referrer`, `landing_url`, `country`, `region`, `city`.
- Campos personalizados dentro de `custom` (o sueltos) con su clave; cualquier otro dato queda en los metadatos y disponible como variable en los correos.
- `audience` suscribe el correo a una audiencia; `email_template` envía además esa plantilla; `email_variables` agrega variables.
- Si llamas desde el backend de un sitio, **envía la `ip` del visitante**.
- Respuestas: `201` · `401` clave inválida · `403` origen desactivado · `422` validación · `429` límite (120/min por clave).

### Envío de emails — `POST /api/v1/emails/send`

Crea una clave en *Email marketing → API e integraciones* (permisos `emails.send`, `emails.read`). Autenticación `Authorization: Bearer <clave>` (o `X-Api-Key`). **Úsala solo desde un servidor.**

```bash
curl -X POST https://TU-DOMINIO/api/v1/emails/send \
  -H "Authorization: Bearer TU_CLAVE" -H "Content-Type: application/json" \
  -d '{ "template": "bienvenida",
        "to": { "email": "maria@ejemplo.com", "name": "María González" },
        "variables": { "first_name": "María", "items": [{ "nombre": "Plan Pro" }] } }'
```

| Parámetro | Descripción |
| --- | --- |
| `template` \* / `template_id` | Slug o id de la plantilla activa. |
| `to` \* | Correo, `{email, name}` o arreglo (máx. 50). |
| `variables` | Valores de variables; cualquier otro parámetro también se toma como variable. |
| `lead_id` | Carga los datos de ese prospecto como variables. |
| `subject`, `from_email`, `delay_minutes` / `send_at` | Reemplazan asunto/remitente o programan. |
| `track_opens`, `track_clicks` | Medición (apagada por defecto en transaccionales). |
| Header `Idempotency-Key` | Evita duplicados al reintentar. |

Responde `202` con el estado en cola; `GET /emails/{id}` consulta estado, aperturas, clics y eventos. Límite: 60/min por clave.

---

## 7. Tareas programadas y colas

El sistema necesita un **worker de cola** (`php artisan queue:work`) y el **scheduler** de Laravel (`* * * * * php artisan schedule:run`; en local `php artisan schedule:work`). En Docker ambos corren bajo Supervisor.

| Tarea | Frecuencia | Qué hace |
| --- | --- | --- |
| `campaigns:dispatch-due` | cada minuto | Envía los boletines programados. |
| `uf:sync` | 00:10 y 08:30 | Actualiza el valor de la UF y su historial. |
| `leads:rescore --open` | 03:30 | Recalcula puntajes de prospectos en curso. |
| `billing:run` | 09:00 (hora de Chile) | Genera los cobros de servicios recurrentes y envía los recordatorios de pago. |

---

## 8. Despliegue

El sistema está pensado para correr en contenedores, **incluida la base de datos**, con el patrón de [Dokploy](https://dokploy.com) (también funciona con cualquier orquestador compatible con Docker Compose).

- `Dockerfile` multi-stage: instala dependencias, compila los assets (Wayfinder + Vite) y deja una imagen final PHP-FPM + Nginx + Supervisor sin Node. Supervisor ejecuta **web**, **cola** y **scheduler**.
- `docker-compose.yml`: servicios de aplicación, **MariaDB 11.4** y **Redis** (sesiones y caché, persistente). **Sin puertos publicados**: el enrutamiento lo gestiona el proxy (Traefik); la base y Redis solo son visibles en la red interna. Volúmenes para archivos (`storage`) y datos.
- `docker/entrypoint.sh`: espera la base, ejecuta `migrate --force`, instala los datos base **solo si el sistema está vacío** y cachea configuración, rutas, eventos y vistas.
- Detrás de un proxy se confía en `X-Forwarded-*` (`TRUSTED_PROXIES`), de modo que URLs, cookies seguras y assets salen en `https`.

**Pasos:** crea el servicio *Compose* con el repositorio y `./docker-compose.yml`, carga las variables de `.env.dokploy.example` (define `APP_KEY`, credenciales de base de datos y `ADMIN_PASSWORD`), asigna el dominio con HTTPS al servicio de la aplicación (puerto 80) y apunta el DNS al servidor. Si usas el portal en otro dominio, agrégalo también al servicio y define `PORTAL_DOMAIN`. Tras el primer ingreso cambia la contraseña del administrador y configura el correo (Resend), la IA, los datos de tu empresa y los datos bancarios desde la propia interfaz.

> **Producción:** mantén un worker de cola y el scheduler activos, define `APP_URL` con la URL pública y respalda el volumen de la base de datos y el de archivos (PDF de facturas y firmas).

---

## 9. Personalización de marca

La identidad visual es configurable sin tocar la lógica:

- **Datos de la empresa** (*Configuración → Datos de la agencia*, permiso `agency.manage`): razón social, RUT, dirección, correo, teléfono y redes que salen en la firma y el pie de propuestas y correos.
- **Logo y colores:** logos en `public/brand/`, paleta y tipografía en `resources/css/app.css` (variables `--primary`, `--sidebar`, etc.) y color de la barra de progreso en `resources/js/app.ts`. El logo del menú está en `resources/js/components/BrandLogo.vue`.
- **Textos de IA:** el *contexto de marca* que reciben los asistentes se edita desde la interfaz (por defecto en `app/Services/Ai/BrandContext.php`).
- **Plantillas de correo:** se editan en *Email marketing → Plantillas*; los diseños base están en `resources/email-designs`.
- **Catálogo, etapas, orígenes y campos personalizados:** se administran desde *Configuración*.

---

## 10. Rendimiento

- **Medir:** los administradores reciben la cabecera `Server-Timing` (`boot`, `app`, `db` con cantidad de consultas y memoria); `PERF_HEADERS=true` la emite para todos.
- **Redis** para sesiones y caché; la cola permanece en base de datos para no perder tareas (`QUEUE_CONNECTION=redis` es opcional).
- **Arranque en frío:** caché de configuración, rutas, eventos y vistas; OPcache sin revalidación; MariaDB con `skip-name-resolve`.
- **Navegador:** precarga de pantallas al pasar el mouse, calentamiento en segundo plano de los módulos más usados, assets con caché de 1 año y compresión gzip.

---

## 11. Estructura del proyecto

```
app/Services/LeadService.php        prospectos: alta, movimiento, asignación, historial y automatizaciones
app/Services/Email/                 motor de plantillas, proveedores (Resend/log), composición, audiencias, envío por lotes
app/Services/Ai/                    AiGateway (SDK laravel/ai), catálogo de proveedores, asistentes, consumo y costos
app/Services/Agent/                 Agent: herramientas, acciones con confirmación, lector de documentos
app/Services/Billing/               servicios contratados, cobros, recordatorios, portal, datos bancarios, indicadores
app/Services/Proposals/             constructor y cálculo de propuestas
app/Http/Controllers/               web, API (Api/), portal (Portal/), cobranza (Billing/), email (Email/), público y webhooks
app/Http/Middleware/                aislamiento del portal, tiempos de respuesta
app/Jobs/                           envío de correos, boletines y análisis de prospectos
config/permissions.php              catálogo de permisos y roles base
resources/js/pages/                 pantallas Inertia (leads, clients, proposals, contracts, billing, accounts, portal, email, ai…)
resources/js/components/            Kanban, editor de correos, Agent, facturación y componentes de interfaz (shadcn-vue)
resources/js/lib/emailBuilder.ts    compilador de bloques → HTML de email
resources/email-designs/            plantillas de correo del sistema
database/migrations|seeders|factories
tests/Feature/                      pruebas por módulo (CRM, propuestas, email, IA, Agent, cobranza)
docker/ · Dockerfile · docker-compose.yml
```

## 12. Comandos útiles

```bash
composer run dev                  # servidor + worker de cola + Vite
php artisan schedule:work         # tareas programadas en local
php artisan queue:work            # worker de cola
php artisan billing:run           # cobros recurrentes y recordatorios (manual)
php artisan crm:seed-demo         # datos de demostración
php artisan crm:purge-demo        # limpia datos de prueba (ver --help; destructivo)
php artisan test                  # requiere dependencias de desarrollo (composer install)
npm run types:check               # tipos TypeScript / Vue
npm run build                     # build de producción
```

---

**Licencia y soporte:** proyecto propietario. Consulta con el equipo responsable para soporte, personalización y despliegues.
