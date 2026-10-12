<?php

namespace App\Services\Proposals;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Services\UfService;
use App\Support\Honorarios;
use App\Support\ProposalText;
use App\Support\Rut;
use Illuminate\Support\Facades\DB;

/** Arma, valida y persiste propuestas. Los totales siempre se recalculan en el servidor. */
class ProposalBuilder
{
    /** Secciones con las que parte cada propuesta (editables). @return array<int, array{title: string, body: string}> */
    public static function defaultSections(): array
    {
        return [
            ['title' => 'Resumen del proyecto', 'body' => "Gracias por confiar en nosotros, **[CLIENTE]**. En Vivienda en Regla trabajamos regularizaciones, planos y diseño de interiores con un enfoque ordenado, minimalista y cercano: entendemos tu propiedad o tu espacio, definimos el camino y te acompañamos hasta la entrega.\n\nEste documento resume lo que proponemos, cómo lo haremos y los honorarios asociados."],
            ['title' => 'Objetivos', 'body' => "- Resolver la necesidad planteada con un proyecto claro, bien documentado y ajustado a la normativa\n- Dar tranquilidad: trámites ordenados, plazos informados y comunicación fluida\n- Entregar espacios y planos que se puedan usar, habitar y mostrar con confianza"],
            ['title' => 'Alcance de los servicios', 'body' => 'A continuación se detallan los servicios incluidos, sus entregables y los honorarios de cada uno.'],
            ['title' => 'Etapas y plazos', 'body' => "- **Etapa 1:** visita, levantamiento y revisión de antecedentes\n- **Etapa 2:** anteproyecto o planos, con revisión contigo\n- **Etapa 3:** ingreso y seguimiento de trámites (si corresponde) o ejecución\n- **Cierre:** entrega de planos, documentos y recepción\n\nLos plazos son referenciales; los de la municipalidad u otros organismos dependen de ellos."],
            ['title' => 'Condiciones comerciales', 'body' => "- Honorarios expresados netos en la moneda indicada (UF o pesos); el total incluye la retención de la boleta de honorarios, que se emite por ese monto (sin IVA)\n- La UF de referencia es la del día de emisión de esta propuesta (queda indicada en el documento)\n- Forma de pago habitual: 50% al aceptar y 50% al entregar (se puede pactar por etapas)\n- Derechos municipales, certificados, copias, topografía y otros gastos de terceros no están incluidos, salvo que se indique\n- Si el proyecto incluye ejecución de obra, esta se cotiza y se paga aparte, por etapas"],
            ['title' => 'Aceptación', 'body' => 'Para aceptar esta propuesta, ingrese al enlace recibido y presione «Aceptar propuesta», o responda este correo indicando su conformidad.'],
        ];
    }

    /** Destinatario sugerido desde el cliente (prioridad) y el lead. @return array<string, mixed> */
    public static function recipientFor(?Client $client, ?Lead $lead): array
    {
        $company = $client?->name ?: $lead?->company;

        return [
            'company' => $company,
            'legal_name' => $client?->legal_name,
            'rut' => $client?->tax_id,
            'activity' => $client?->activity,
            'address' => collect([$client?->address, $client?->commune, $client?->city])->filter()->implode(', ') ?: null,
            'contact_name' => $client?->contact_name ?: $lead?->full_name,
            'contact_role' => $client?->contact_role ?: $lead?->job_title,
            'email' => $client?->email ?: $lead?->email,
            'phone' => $client?->phone ?: $lead?->phone,
        ];
    }

    /** Propuesta en memoria (sin guardar) a partir de la carga del formulario: sirve para la vista previa. @param array<string, mixed> $data */
    public function fromPayload(array $data, ?Proposal $existing = null): Proposal
    {
        $p = $existing ?? new Proposal(['status' => 'draft']);
        $items = collect($data['items'] ?? [])->values()->map(fn ($i, $n) => new ProposalItem([
            'service_id' => $i['service_id'] ?? null,
            'name' => $i['name'],
            'description' => isset($i['description']) ? ProposalText::sanitize((string) $i['description']) : null,
            'deliverables' => array_values(array_filter($i['deliverables'] ?? [])),
            'billing' => $i['billing'] ?? 'one_time',
            'unit' => $i['unit'] ?? 'servicio',
            'quantity' => $i['quantity'] ?? 1,
            'unit_price' => (float) ($i['unit_price'] ?? 0),
            'discount_pct' => (int) ($i['discount_pct'] ?? 0),
            'sort_order' => $n,
        ]));

        $recipient = collect($data['recipient'] ?? [])->map(fn ($v) => is_string($v) ? trim($v) : $v)->filter(fn ($v) => $v !== '' && $v !== null)->all();
        if (! empty($recipient['rut'])) {
            $recipient['rut'] = Rut::format($recipient['rut']);
        }

        $p->fill([
            'title' => $data['title'] ?? 'Propuesta comercial',
            'client_id' => $data['client_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'recipient' => $recipient,
            'sections' => collect($data['sections'] ?? [])->map(fn ($s) => ['title' => trim((string) ($s['title'] ?? '')), 'body' => ProposalText::isHtml($s['body'] ?? '') ? ProposalText::sanitize((string) $s['body']) : trim((string) ($s['body'] ?? ''))])->filter(fn ($s) => $s['title'] !== '' || $s['body'] !== '')->values()->all(),
            'valid_until' => $data['valid_until'] ?? null,
            'contract_months' => $data['contract_months'] ?? null,
            'discount_type' => $data['discount_type'] ?? 'percent',
            'discount_value' => (float) ($data['discount_value'] ?? 0),
            'currency' => in_array($data['currency'] ?? 'UF', ['UF', 'CLP'], true) ? $data['currency'] : 'UF',
            'tax_mode' => $mode = (array_key_exists($data['tax_mode'] ?? '', Proposal::TAX_MODES) ? $data['tax_mode'] : 'honorarios'),
            'tax_rate' => match ($mode) {
                'none' => 0,
                'honorarios' => isset($data['tax_rate']) && (float) $data['tax_rate'] > 0 ? (float) $data['tax_rate'] : Honorarios::rate(),
                default => (float) ($data['tax_rate'] ?? 19),
            },
            'internal_notes' => isset($data['internal_notes']) ? ProposalText::sanitize((string) $data['internal_notes']) : null,
        ]);
        if (! empty($data['user_id'])) {
            $p->user_id = $data['user_id'];
        }

        $p->setRelation('items', $items);
        $this->snapshotUf($p);
        $this->recalculate($p);

        return $p;
    }

    /**
     * La UF de referencia es la del día en que se trabaja la cotización mientras es borrador; al enviarla
     * queda congelada (ver freezeUf).
     */
    public function snapshotUf(Proposal $p): void
    {
        if (($p->exists && $p->status !== 'draft') || ($p->uf_value && $p->exists && $p->uf_date?->isToday())) {
            return;
        }

        if ($uf = app(UfService::class)->today()) {
            $p->uf_value = $uf['value'];
            $p->uf_date = $uf['date'];
        }
    }

    /** Congela la UF al emitir/enviar: desde ahí el valor y el día quedan fijos. */
    public function freezeUf(Proposal $p): void
    {
        if ($uf = app(UfService::class)->today()) {
            $p->forceFill(['uf_value' => $uf['value'], 'uf_date' => $uf['date']])->save();
        }
    }

    public function recalculate(Proposal $p): Proposal
    {
        $t = ProposalCalculator::totals(
            $p->items->map(fn (ProposalItem $i) => $i->only(['billing', 'quantity', 'unit_price', 'discount_pct']))->all(),
            (string) $p->discount_type,
            (float) $p->discount_value,
            $p->contract_months,
            (float) $p->tax_rate,
            $p->decimals(),
            (string) ($p->tax_mode ?: 'honorarios'),
        );
        $p->forceFill([
            'total_one_time' => $t['total_one_time'], 'total_monthly' => $t['total_monthly'],
            'total_net' => $t['total_net'], 'total_tax' => $t['total_tax'], 'total_gross' => $t['total_gross'],
        ]);

        return $p;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, ?Proposal $existing = null, ?int $userId = null): Proposal
    {
        return DB::transaction(function () use ($data, $existing, $userId) {
            $p = $this->fromPayload($data, $existing);
            $items = $p->items;
            if (! $p->exists) {
                $p->user_id ??= $userId;
            }
            $p->save();

            $p->items()->delete();
            $p->items()->saveMany($items->all());
            $p->setRelation('items', $p->items()->get());

            return $p;
        });
    }

    /** Copia una propuesta como borrador nuevo (nueva versión) para el mismo cliente/lead. */
    public function duplicate(Proposal $source, ?int $userId): Proposal
    {
        $data = [
            'currency' => $source->currency,
            'title' => preg_replace('/ \(v\d+\)$/', '', $source->title).' (v'.(1 + Proposal::where('lead_id', $source->lead_id)->where('client_id', $source->client_id)->count()).')',
            'client_id' => $source->client_id,
            'lead_id' => $source->lead_id,
            'recipient' => $source->recipient,
            'sections' => $source->sections,
            'valid_until' => now()->addDays(15)->toDateString(),
            'contract_months' => $source->contract_months,
            'discount_type' => $source->discount_type,
            'discount_value' => $source->discount_value,
            'tax_mode' => $source->tax_mode,
            'tax_rate' => $source->tax_rate,
            'items' => $source->items->map(fn ($i) => $i->only(['service_id', 'name', 'description', 'deliverables', 'billing', 'unit', 'quantity', 'unit_price', 'discount_pct']))->all(),
        ];

        return $this->save($data, null, $userId);
    }
}
