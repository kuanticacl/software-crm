# Integración de la landing (www.mecaniq.cl) con el CRM — frontend y API

Guía para conectar la landing (Nuxt 4) con el CRM `https://crm.mecaniq.cl`: **formulario de demo**, **boletín**, la asistente
**Scarlett** (chat con IA) y los **planes/precios**. Todo pasa por rutas de servidor de la landing: **la API key nunca va al navegador.**

```
Navegador ──► /api/crm/* (Nitro, landing) ──► https://crm.mecaniq.cl/api/v1/*   (X-Api-Key en el servidor)
```

## 1. Credenciales

| Variable (Nuxt) | Valor |
|---|---|
| `NUXT_CRM_URL` | `https://crm.mecaniq.cl` |
| `NUXT_CRM_API_KEY` | API key del origen **«Landing MecaniQ»** (formato `qb_…`; se entrega por canal privado, **no se sube al repositorio**) |
| `NUXT_CRM_PLANS_KEY` *(opcional)* | API key del CRM con permiso `billing.read` (Email → API de envío) solo para mostrar precios |

La key identifica el **origen** de cada cliente («Landing MecaniQ»). Si se filtra: CRM → Configuración CRM → *Orígenes de clientes* →
«Regenerar key» y actualizar la variable.

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  runtimeConfig: {
    crmUrl: '',        // NUXT_CRM_URL
    crmApiKey: '',     // NUXT_CRM_API_KEY  (solo servidor)
    crmPlansKey: '',   // NUXT_CRM_PLANS_KEY
  },
})
```

## 2. Rutas de servidor (Nitro)

### Utilidad común — `server/utils/crm.ts`

```ts
import type { H3Event } from 'h3'

export async function crm<T = any>(event: H3Event, path: string, init: { method?: 'GET' | 'POST'; body?: unknown; key?: 'api' | 'plans' } = {}): Promise<T> {
  const cfg = useRuntimeConfig(event)
  const key = init.key === 'plans' ? cfg.crmPlansKey : cfg.crmApiKey
  try {
    return await $fetch<T>(`${cfg.crmUrl}/api/v1/${path}`, {
      method: init.method ?? 'GET',
      headers: { 'X-Api-Key': key, Accept: 'application/json' },
      body: init.body as any,
      timeout: 25_000,
    })
  } catch (e: any) {
    // Reenvía el código y el mensaje de validación del CRM (422 con `errors`) sin exponer detalles internos.
    throw createError({ statusCode: e?.response?.status ?? 502, statusMessage: 'No se pudo contactar al CRM', data: e?.data })
  }
}

/** IP real del visitante (el CRM la guarda en el cliente y la usa para limitar abusos). */
export const visitorIp = (event: H3Event) => getRequestIP(event, { xForwardedFor: true })
```

### Formulario de demo — `server/api/crm/lead.post.ts`

```ts
export default defineEventHandler(async (event) => {
  const b = await readBody(event)
  if (b.website) return { ok: true } // honeypot: campo oculto que un humano no llena

  const lead = await crm(event, 'leads', {
    method: 'POST',
    body: {
      first_name: b.first_name, last_name: b.last_name, email: b.email, phone: b.phone,
      company: b.company,                       // nombre del taller
      message: b.message,
      utm_source: b.utm_source, utm_medium: b.utm_medium, utm_campaign: b.utm_campaign,
      landing_url: b.landing_url, referrer: b.referrer,
      ip: visitorIp(event), user_agent: getHeader(event, 'user-agent'),
      // Campos personalizados (ya existen en el CRM):
      tipo_solicitud: 'demo',
      tipo_taller: b.tipo_taller,               // Mecánica general | Multimarca | Planchado y pintura | Neumáticos | Eléctrico / electrónico | Flota | Otro
      n_mecanicos: b.n_mecanicos,
      ciudad: b.ciudad,
      sistema_actual: b.sistema_actual,         // cómo gestiona hoy el taller
      fecha_preferida: b.fecha_preferida,       // YYYY-MM-DD
      horario_preferido: b.horario_preferido,   // mañana | tarde
      // Correo de agradecimiento automático (plantilla del CRM):
      email_template: 'confirmacion-demo',
    },
  })
  return { ok: true, id: lead.data.id }
})
```

### Boletín — `server/api/crm/newsletter.post.ts`

```ts
export default defineEventHandler(async (event) => {
  const { first_name, email, website } = await readBody(event)
  if (website) return { ok: true }
  await crm(event, 'leads', {
    method: 'POST',
    body: { first_name, email, ip: visitorIp(event), landing_url: getHeader(event, 'referer'),
            tipo_solicitud: 'boletín', audience: 'Boletín MecaniQ', email_template: 'bienvenida-boletin' },
  })
  return { ok: true }
})
```

### Scarlett — `server/api/scarlett/config.get.ts` y `chat.post.ts`

```ts
// config.get.ts — ¿mostrar el botón de Scarlett? (activa y con IA disponible)
export default defineEventHandler(async (event) => (await crm(event, 'scarlett/config')).data) // { enabled, greeting }
```

```ts
// chat.post.ts
export default defineEventHandler(async (event) => {
  const { session, messages, privacy_notice } = await readBody(event)
  const r = await crm(event, 'scarlett/chat', {
    method: 'POST',
    body: {
      session,                                   // id de conversación: 8–64 caracteres [A-Za-z0-9_-]
      messages: (messages ?? []).slice(-20),     // historial completo cada vez (el CRM no lo guarda); máx. 20 mensajes de 1.500 caracteres
      ip: visitorIp(event),                      // IP del visitante: los límites se aplican por visitante
      user_agent: getHeader(event, 'user-agent'),
      landing_url: getHeader(event, 'referer'),
      privacy_notice,                            // versión del aviso de privacidad que vio la persona
    },
  })
  return r.data // { reply, contact_saved, meeting_requested }
})
```

### Planes y precios (opcional) — `server/api/crm/plans.get.ts`

```ts
export default defineCachedEventHandler(async (event) => (await crm(event, 'plans', { key: 'plans' })).data, { maxAge: 300 })
// → [{ id: 'pro', name: 'Pro', prices: { monthly: { amount_net: 39990 }, quarterly: …, annual: … }, limits: [{ key, name, unit, value, unlimited }], features: [...] }]
```
Los valores son **netos** en CLP: muestra «+ IVA» o multiplica por 1,19.

## 3. Frontend

### Composable — `composables/useCrm.ts`

```ts
export function useCrmLead() {
  const sending = ref(false), error = ref(''), done = ref(false)
  async function submit(form: Record<string, any>) {
    sending.value = true; error.value = ''
    const q = new URLSearchParams(location.search)
    try {
      await $fetch('/api/crm/lead', { method: 'POST', body: {
        ...form, landing_url: location.href, referrer: document.referrer || undefined,
        utm_source: q.get('utm_source') ?? undefined, utm_medium: q.get('utm_medium') ?? undefined, utm_campaign: q.get('utm_campaign') ?? undefined,
      } })
      done.value = true
    } catch (e: any) {
      const errs = e?.data?.data?.errors as Record<string, string[]> | undefined
      error.value = errs ? Object.values(errs)[0][0] : 'No pudimos enviar tu solicitud. Intenta de nuevo o escríbenos por WhatsApp.'
    } finally { sending.value = false }
  }
  return { submit, sending, error, done }
}
```

### Formulario de demo — `components/DemoForm.vue`

```vue
<script setup lang="ts">
const { submit, sending, error, done } = useCrmLead()
const f = reactive({ first_name: '', last_name: '', email: '', phone: '', company: '', tipo_taller: '', n_mecanicos: '', ciudad: '', message: '', website: '' })
</script>

<template>
  <p v-if="done" class="ok">¡Gracias! Te escribiremos pronto para coordinar tu demo.</p>
  <form v-else @submit.prevent="submit(f)">
    <input v-model="f.first_name" required placeholder="Nombre" autocomplete="given-name" />
    <input v-model="f.last_name" placeholder="Apellido" autocomplete="family-name" />
    <input v-model="f.email" type="email" required placeholder="Correo" autocomplete="email" />
    <input v-model="f.phone" type="tel" placeholder="WhatsApp o teléfono" autocomplete="tel" />
    <input v-model="f.company" required placeholder="Nombre del taller" />
    <select v-model="f.tipo_taller">
      <option value="">Tipo de taller</option>
      <option>Mecánica general</option><option>Multimarca</option><option>Planchado y pintura</option>
      <option>Neumáticos</option><option>Eléctrico / electrónico</option><option>Flota</option><option>Otro</option>
    </select>
    <input v-model="f.n_mecanicos" placeholder="N.º de mecánicos" />
    <input v-model="f.ciudad" placeholder="Ciudad o comuna" />
    <textarea v-model="f.message" placeholder="Cuéntanos de tu taller (opcional)" />
    <input v-model="f.website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true" /> <!-- honeypot -->
    <p class="legal">Al enviar aceptas que usemos tus datos para contactarte sobre MecaniQ. Puedes pedir su eliminación en contacto@mecaniq.cl.</p>
    <p v-if="error" class="err">{{ error }}</p>
    <button :disabled="sending">{{ sending ? 'Enviando…' : 'Agendar demo' }}</button>
  </form>
</template>
```

### Scarlett (botón flotante) — `components/ScarlettChat.vue`

```vue
<script setup lang="ts">
type Msg = { role: 'user' | 'assistant'; content: string }
const { data: cfg } = await useFetch<{ enabled: boolean; greeting: string }>('/api/scarlett/config', { server: false })
const open = ref(false), busy = ref(false), text = ref('')
const session = useState('scarlett-session', () => Math.random().toString(36).slice(2) + Date.now().toString(36)) // 8–64 [A-Za-z0-9]
const messages = ref<Msg[]>([])
watch(() => cfg.value?.greeting, (g) => { if (g && !messages.value.length) messages.value.push({ role: 'assistant', content: g }) }, { immediate: true })

async function send() {
  const content = text.value.trim().slice(0, 1500)
  if (!content || busy.value) return
  messages.value.push({ role: 'user', content }); text.value = ''; busy.value = true
  try {
    // El saludo inicial es solo de pantalla: se envía la conversación sin él.
    const history = messages.value.filter((m, i) => !(i === 0 && m.role === 'assistant'))
    const r = await $fetch<{ reply: string }>('/api/scarlett/chat', { method: 'POST', body: { session: session.value, messages: history, privacy_notice: 'v1' } })
    messages.value.push({ role: 'assistant', content: r.reply })
  } catch (e: any) {
    messages.value.push({ role: 'assistant', content: e?.statusCode === 429 ? 'Estás escribiendo muy rápido; espera unos segundos.' : 'No puedo responder ahora. Escríbenos a contacto@mecaniq.cl.' })
  } finally { busy.value = false }
}
</script>

<template>
  <div v-if="cfg?.enabled" class="scarlett">
    <button v-if="!open" class="fab" aria-label="Hablar con Scarlett" @click="open = true">💬</button>
    <section v-else class="panel" role="dialog" aria-label="Scarlett, ejecutiva virtual de MecaniQ">
      <header>Scarlett · MecaniQ <small>Asistente con IA</small> <button aria-label="Cerrar" @click="open = false">×</button></header>
      <ol>
        <li v-for="(m, i) in messages" :key="i" :class="m.role">{{ m.content }}</li>
        <li v-if="busy" class="assistant">…</li>
      </ol>
      <form @submit.prevent="send"><input v-model="text" maxlength="1500" placeholder="Escribe tu consulta…" /><button :disabled="busy">Enviar</button></form>
      <p class="legal">Scarlett es una asistente de inteligencia artificial. Si compartes tus datos, solo los guarda con tu consentimiento.</p>
    </section>
  </div>
</template>
```
Muestra el texto con `{{ }}` (escapado); **no uses `v-html`** con las respuestas.

## 4. Contrato de la API (por si se integra desde otro stack)

Base `https://crm.mecaniq.cl/api/v1` · header `X-Api-Key: <key>` (o `Authorization: Bearer`) · JSON · límite 120/min por key.

| Método | Ruta | Uso |
|---|---|---|
| POST | `/leads` | Crea un cliente (prospecto). Requiere `first_name` (o `name`) y `email` o `phone`. Acepta `company, job_title, message, utm_*, ip, user_agent, referrer, landing_url, country, region, city`, campos personalizados sueltos o en `custom`, `audience` (suscribe al boletín) y `email_template` + `email_variables` (envía una plantilla). Responde `201 { data: { id, source, stage, email, audience } }` |
| GET | `/scarlett/config` | `{ data: { enabled, greeting } }` |
| POST | `/scarlett/chat` | Ver sección 2. `{ data: { reply, contact_saved, meeting_requested } }`; `503` si Scarlett está desactivada o sin IA; `429` por límite (10 msg/min y 150/día por visitante) |
| GET | `/plans` | Catálogo de planes (**key con permiso `billing.read`**) |

Campos personalizados disponibles: `tipo_solicitud, fecha_preferida, horario_preferido, tipo_taller, n_mecanicos, ciudad, sistema_actual, interes`.
Plantillas de correo: `confirmacion-demo` (confirma la solicitud de demo) y `bienvenida-boletin`. Además, el CRM envía un
agradecimiento automático a los registros web y avisa por correo al equipo comercial.

Errores: `401` key inválida o ausente · `403` origen desactivado · `422` validación (`{ message, errors: { campo: [..] } }`) · `429` demasiadas peticiones.

## 5. Probar

```bash
curl -s -X POST https://crm.mecaniq.cl/api/v1/leads \
  -H "X-Api-Key: $NUXT_CRM_API_KEY" -H "Content-Type: application/json" \
  -d '{"first_name":"Prueba","email":"prueba@ejemplo.cl","company":"Taller Prueba","tipo_taller":"Multimarca","tipo_solicitud":"demo"}'

curl -s https://crm.mecaniq.cl/api/v1/scarlett/config -H "X-Api-Key: $NUXT_CRM_API_KEY"
```
El cliente aparece en **CRM → Clientes** (origen «Landing MecaniQ», etapa «Ingreso»). Bórralo de la Papelera tras la prueba.

## 6. Checklist de salida

- [ ] `NUXT_CRM_URL` y `NUXT_CRM_API_KEY` definidas en Dokploy (no en el repositorio ni en `app.config`/`public`).
- [ ] Rutas `/api/crm/*` y `/api/scarlett/*` probadas con `curl` en el dominio de la landing.
- [ ] Aviso de privacidad visible junto al formulario y al chat (consentimiento).
- [ ] En el CRM: Scarlett activa y con proveedor de IA (*Inteligencia artificial → Proveedores* y *Scarlett (web)*), Resend configurado (*Email → Configuración*) y las plantillas activas.
- [ ] Honeypot y `ip` del visitante enviados; sin `v-html` en respuestas de Scarlett.
