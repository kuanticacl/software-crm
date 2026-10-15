# Asignación automática y alertas de clientes nuevos

## Qué hace

- **Ningún cliente queda sin asignar.** Al crearse un cliente (web, API, formularios, Alfred o a mano) sin responsable:
  1. se asigna a los **responsables de su origen** (uno o varios; si son varios se reparten parejo, gana quien tenga menos clientes de ese origen);
  2. si el origen no tiene responsables, al **responsable por defecto**.
- **Alerta por correo** a cada cliente nuevo, con la plantilla *Alerta de cliente nuevo* (Email → Plantillas) y la
  automatización *Alerta de cliente nuevo al equipo* (Email → Automatizaciones, modo «El equipo»). Reciben el aviso, sin
  repetirse: el responsable asignado, todos los responsables del origen y los **correos por defecto**.

## Dónde se configura

- **Orígenes de clientes** (`/crm/sources`): *Responsables del origen* (editar un origen) y la tarjeta
  *Asignación y alertas por defecto* (responsable por defecto y correos que reciben todas las alertas).
- **Email → Automatizaciones**: se puede apagar, cambiar el asunto o filtrar por origen/etapa.
- **Email → Plantillas**: editar el diseño del aviso (variables `lead_*`, `assignee_name`, `recipient_name`).

## Valores iniciales (migración `create_default_new_lead_alert`)

Responsable por defecto = usuario `arossi@quiebre.cl` (si existe y está activo) y correo de alerta `arossi@quiebre.cl`.
Los clientes que ya estaban sin asignar **no** se tocan; se pueden asignar desde el Kanban o la lista.

## Notas técnicas

`App\Services\Leads\LeadAssignment` decide asignación y destinatarios; `LeadService::create` la aplica sin interrumpir el
alta si falla; `AutomationRunner` envía el modo `team` (un correo por destinatario, sin seguimiento de aperturas).
