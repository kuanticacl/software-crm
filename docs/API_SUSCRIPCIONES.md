# API de suscripciones y cobros (app móvil y sitio web)

Permite a la app MecaniQ y al sitio consultar el **plan, los límites, el acceso, los cobros y el saldo** de cada taller.
La llave que asocia el *tenant* en cualquier plataforma es el **`customer_id`** de la empresa (`cus_…`), visible en el CRM
(Empresas → ficha, y Suscripciones). No cambia nunca.

- Base: `https://crm.mecaniq.cl/api/v1`
- Autenticación: API key del CRM (Email → *API de envío* → crear llave) con permiso `billing.read` (lectura) y/o `billing.write` (registrar pagos).
  Se envía como `Authorization: Bearer <key>` o `X-Api-Key: <key>`. **Úsala solo desde un servidor tuyo, nunca embebida en la app móvil.**
- Tope: 120 peticiones por minuto por key. Montos en pesos chilenos (CLP, enteros); `amount_net` es neto y `amount_total` incluye IVA.

## Endpoints

| Método | Ruta | Permiso | Qué devuelve |
|---|---|---|---|
| GET | `/plans` | billing.read | Catálogo público de planes: precios por período y límites (para la página de precios) |
| GET | `/customers/{customer_id}` | billing.read | Empresa + suscripción vigente + `access` + saldo |
| GET | `/customers/{customer_id}/subscription` | billing.read | Suscripción vigente (plan, período, renovación, fechas, límites) |
| GET | `/customers/{customer_id}/limits` | billing.read | Límites efectivos (plan + ajustes del cliente); `value: null` / `unlimited: true` = sin tope |
| GET | `/customers/{customer_id}/balance` | billing.read | Pendiente, vencido y próximo vencimiento |
| GET | `/customers/{customer_id}/invoices?status=open\|overdue\|paid\|void\|all&per_page=20` | billing.read | Cobros paginados, con `payment_instructions` (cuentas de transferencia) |
| GET | `/customers/{customer_id}/invoices/{number}` | billing.read | Un cobro |
| POST | `/customers/{customer_id}/invoices/{number}/payments` | billing.write | Registra el pago (`method`, `reference`, `paid_at`). Idempotente |

## `access` — qué debe hacer la app

```json
{ "allowed": true, "status": "past_due", "reason": "Tiene un pago vencido.", "payment_overdue": true }
```

- `allowed`: `true` para suscripciones `active` y `past_due`; `false` para `suspended`, `cancelled`, `expired` o sin suscripción.
- El CRM **no bloquea solo** por morosidad: marca `past_due` y envía los recordatorios; tú decides suspender (Suscripciones → *Suspender acceso*) y la API pasa a `allowed: false` al instante.
- Estados de suscripción: `active`, `past_due`, `suspended`, `cancelled`, `expired`. Estados de cobro: `pending`, `overdue` (pendiente y vencido), `paid`, `void`.

## Ejemplo

```bash
curl -s https://crm.mecaniq.cl/api/v1/customers/cus_abc123def456ghij \
  -H "Authorization: Bearer $MECANIQ_API_KEY"
```

```json
{ "data": {
  "customer_id": "cus_abc123def456ghij", "name": "Taller Los Robles", "tax_id": "76.511.230-3",
  "subscription": {
    "id": "sub_…", "status": "active", "access": true,
    "plan": { "id": "pro", "name": "Pro" }, "period": "monthly", "amount_net": 39990, "amount_total": 47588,
    "auto_renew": true, "current_period_start": "2026-10-19", "current_period_end": "2026-11-19",
    "limits": [ { "key": "usuarios", "name": "Usuarios", "unit": "usuarios", "value": 10, "unlimited": false },
                { "key": "api_por_minuto", "name": "Peticiones de API por minuto", "unit": "req/min", "value": 120, "unlimited": false } ]
  },
  "access": { "allowed": true, "status": "active", "reason": null, "payment_overdue": false },
  "balance": { "currency": "CLP", "pending_count": 1, "pending_total": 47588, "overdue_count": 0, "overdue_total": 0,
               "next_due": { "number": "C-2026-00012", "due_date": "2026-11-19", "amount_total": 47588 } }
} }
```

## Límites del plan (rate limits)

Los tipos de límite se crean en **Suscripciones → Planes y límites → Tipos de límite** (por ejemplo `usuarios`, `ordenes_mes`,
`api_por_minuto`) y cada plan fija su valor (vacío = ilimitado). Cada suscripción puede tener ajustes propios.
La API entrega el valor vigente por `key`; **quien aplica el límite es tu backend/app** (el CRM no mide el uso).

## Cobranza automática

Se configura en *Configuración de cobros* y *Email → Automatizaciones* (las mismas del boletín):

- `billing:run` corre cada día a las 08:00 (hora de Chile): emite el cobro de la renovación N días antes del término, avanza el período y marca atrasos.
- Disparadores: cobro emitido, **N días antes/después del vencimiento**, pago recibido, suscripción iniciada y **N días antes de renovar o terminar**.
  Vienen 8 automatizaciones de ejemplo, editables. Cada envío se registra para no repetirse.
- Variables de plantilla: `first_name`, `company`, `plan_name`, `period`, `invoice_number`, `concept`, `amount_net`, `amount_total`, `due_date`, `headline`, `message`, `payment_link`, `bank_details`, `renewal_date`, `renewal_price`, `auto_renew`, `customer_id`.
