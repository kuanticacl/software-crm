# Alfred — ejecutivo virtual del sitio web

Asistente flotante de quiebre.cl. Conversa con visitantes, responde sobre la agencia, capta sus datos y solicita
reuniones. Se administra en **Inteligencia artificial → Alfred (web)** (permiso `ai.manage`).

## Diseño de seguridad

- **Sin acceso al CRM.** Solo tiene dos herramientas de *escritura* (`save_contact`, `request_meeting`) y ninguna de
  lectura. Un test (`test_alfred_only_has_write_tools_and_none_that_read_the_crm`) falla si se agrega otra.
- **Solo conoce** el prompt, el «Conocimiento autorizado» (texto que redacta la empresa) y los datos públicos de
  «Datos de mi empresa». Las **reglas de seguridad** (`AlfredSettings::SAFETY`) se agregan siempre al final y no se
  editan desde el CRM.
- No recibe las «Instrucciones para toda la IA» (son de uso interno). Temperatura baja y respuestas acotadas.
- Consentimiento explícito antes de guardar datos; las reuniones quedan **«por confirmar»** (actividad `meeting` con
  `status=pending` y próximo seguimiento) y se acepta una solicitud por conversación.
- La conversación **no se guarda**: el sitio envía el historial en cada llamada (validado: solo `user`/`assistant`,
  máx. 20 mensajes de 1.500 caracteres). Si la persona deja sus datos, se agrega una nota al cliente con los últimos
  mensajes.
- Límites: 10 mensajes/min y 150/día por visitante (IP que informa el sitio) y 3.000/día por origen.

## API (misma API key por origen que el ingreso de leads, header `X-Api-Key`)

`GET /api/v1/alfred/config` → `{ data: { enabled, greeting } }` (`enabled` = activo **y** con proveedor de IA).

`POST /api/v1/alfred/chat`

```json
{
  "session": "id-de-conversación (8–64, A–Z a–z 0–9 _ -)",
  "messages": [{ "role": "user", "content": "Hola" }],
  "ip": "IP del visitante", "user_agent": "...", "landing_url": "...", "referrer": "...",
  "privacy_notice": "versión del aviso", "utm_source": "..."
}
```

Responde `{ data: { reply, contact_saved, meeting_requested } }`; `503` si Alfred está desactivado o no hay IA.
