<?php

namespace App\Services\RealEstate;

use App\Models\Client;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\LeadService;
use App\Services\UfService;
use App\Support\Rut;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reserva de unidades: bloquea las unidades (con lock para que dos ejecutivos no reserven la misma), registra el pago
 * de la reserva, arma el plan de pagos del pie y mueve los estados de unidad, cotización y prospecto.
 */
class ReservationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): Reservation
    {
        return DB::transaction(function () use ($data, $user) {
            $quote = ! empty($data['quote_id']) ? Quote::with('items')->findOrFail($data['quote_id']) : null;
            $project = Project::findOrFail($quote?->project_id ?? $data['project_id']);

            // Unidades y precios: los de la cotización o los indicados (con su descuento).
            $rows = $quote
                ? $quote->items->map(fn ($i) => ['unit_id' => $i->unit_id, 'list_price_uf' => $i->list_price_uf, 'discount_pct' => $i->discount_pct])->values()
                : collect($data['items'] ?? [])->values();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Indica al menos una unidad para reservar.']);
            }

            $units = Unit::where('project_id', $project->id)->whereIn('id', $rows->pluck('unit_id')->filter())->lockForUpdate()->get()->keyBy('id');
            foreach ($rows as $n => $row) {
                $unit = $units->get((int) $row['unit_id']);
                if (! $unit) {
                    throw ValidationException::withMessages(['items' => 'Una de las unidades ya no existe en el proyecto.']);
                }
                if ($unit->status !== 'disponible') {
                    throw ValidationException::withMessages(['items' => "{$unit->label()} ya no está disponible (".Unit::STATUSES[$unit->status].'). Actualiza la cotización.']);
                }
            }

            $piePct = (float) ($data['pie_pct'] ?? $quote?->pie_pct ?? $project->default_pie_pct);
            $calc = QuoteCalculator::totals($rows->map(fn ($r) => [
                'list_price_uf' => $r['list_price_uf'] ?? $units[(int) $r['unit_id']]->list_price,
                'discount_pct' => $r['discount_pct'] ?? 0,
            ])->all(), $piePct, (float) ($quote?->mortgage_rate ?? $project->mortgage_rate), (int) ($quote?->mortgage_years ?? $project->mortgage_years));

            $client = $this->buyer($data, $quote, $user);
            $uf = (float) (app(UfService::class)->today()['value'] ?? $quote?->uf_value ?? 0);
            if ($uf <= 0) {
                throw ValidationException::withMessages(['uf' => 'No hay valor UF disponible para hoy. Intenta en unos minutos.']);
            }
            $reservedAt = CarbonImmutable::parse($data['reserved_at'] ?? now()->toDateString());

            $reservation = Reservation::create([
                'number' => Reservation::nextNumber(),
                'project_id' => $project->id,
                'quote_id' => $quote?->id,
                'lead_id' => $data['lead_id'] ?? $quote?->lead_id,
                'client_id' => $client->id,
                'user_id' => $quote?->user_id ?? $user->id,
                'status' => 'reserved',
                'reserved_at' => $reservedAt->toDateString(),
                'promise_due_date' => $data['promise_due_date'] ?? $reservedAt->addDays($project->promise_days)->toDateString(),
                'uf_value' => $uf,
                'subtotal_uf' => $calc['subtotal_uf'],
                'discount_uf' => $calc['discount_uf'],
                'total_uf' => $calc['total_uf'],
                'pie_pct' => $piePct,
                'pie_uf' => $calc['pie_uf'],
                'credit_uf' => $calc['credit_uf'],
                'financing' => $data['financing'] ?? 'credito',
                'bank' => $data['bank'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($rows as $n => $row) {
                $unit = $units[(int) $row['unit_id']];
                $reservation->units()->create([
                    'unit_id' => $unit->id, 'type' => $unit->type, 'code' => $unit->code, 'model' => $unit->model,
                    'list_price_uf' => $row['list_price_uf'] ?? $unit->list_price, 'price_uf' => $calc['items'][$n],
                ]);
                $unit->update(['status' => 'reservada']);
            }

            $this->buildPlan($reservation, $data, $uf, $reservedAt);

            if ($quote) {
                $quote->update(['status' => 'reserved']);
            }
            $this->touchLead($reservation, $user, "Reserva {$reservation->number} · {$project->name}", 'Reservado');

            return $reservation->load(['units', 'payments']);
        });
    }

    /**
     * Plan de pagos: reserva (pesos) + cuotas del pie (UF, descontando la reserva) + saldo (crédito, contado o subsidio).
     *
     * @param  array<string, mixed>  $data
     */
    private function buildPlan(Reservation $r, array $data, float $uf, CarbonImmutable $reservedAt): void
    {
        $reservationClp = (float) ($data['reservation_amount'] ?? 0);
        if ($reservationClp > 0) {
            $paid = (bool) ($data['reservation_paid'] ?? true);
            $r->payments()->create([
                'kind' => 'reserva', 'number' => 0, 'label' => 'Reserva', 'due_date' => $reservedAt->toDateString(),
                'currency' => 'CLP', 'amount' => $reservationClp,
                'status' => $paid ? 'paid' : 'pending', 'paid_at' => $paid ? $reservedAt->toDateString() : null, 'paid_clp' => $paid ? $reservationClp : null,
                'method' => $paid ? ($data['reservation_method'] ?? 'Transferencia') : null, 'reference' => $data['reservation_reference'] ?? null,
            ]);
        }

        $count = (int) ($data['pie_installments'] ?? 0);
        $first = CarbonImmutable::parse($data['first_installment_date'] ?? $reservedAt->addMonthNoOverflow()->toDateString());
        foreach (QuoteCalculator::pieInstallments($r->pie_uf, round($reservationClp / $uf, 2), $count, $first) as $c) {
            $r->payments()->create([
                'kind' => 'pie', 'number' => $c['number'], 'label' => "Cuota {$c['number']} de {$count} del pie",
                'due_date' => $c['due_date'], 'currency' => 'UF', 'amount' => $c['amount'],
            ]);
        }
        // Pie al contado (sin cuotas): una sola cuota por el pie menos la reserva.
        if ($count === 0 && ($rest = round($r->pie_uf - $reservationClp / $uf, 2)) > 0) {
            $r->payments()->create(['kind' => 'pie', 'number' => 1, 'label' => 'Pie al contado', 'due_date' => $r->promise_due_date?->toDateString(), 'currency' => 'UF', 'amount' => $rest]);
        }

        if ($r->credit_uf > 0) {
            $r->payments()->create([
                'kind' => 'saldo', 'number' => 0,
                'label' => 'Saldo · '.(Reservation::FINANCING[$r->financing] ?? 'Crédito hipotecario').($r->bank ? ' ('.$r->bank.')' : ''),
                'due_date' => $r->project?->delivery_date?->toDateString(), 'currency' => 'UF', 'amount' => $r->credit_uf,
            ]);
        }
    }

    /** Comprador: un cliente existente o uno nuevo con los datos del formulario (o del cotizante). @param array<string, mixed> $data */
    private function buyer(array $data, ?Quote $quote, User $user): Client
    {
        if (! empty($data['client_id'])) {
            return Client::findOrFail($data['client_id']);
        }
        $b = $data['buyer'] ?? $quote?->buyer ?? [];
        if (empty($b['name'])) {
            throw ValidationException::withMessages(['buyer.name' => 'Indica el nombre del comprador.']);
        }
        $rut = ! empty($b['rut']) ? Rut::format($b['rut']) : null;
        if ($rut && ($existing = Client::where('tax_id', $rut)->first())) {
            return $existing;
        }

        return Client::create([
            'kind' => 'person', 'name' => $b['name'], 'tax_id' => $rut, 'email' => $b['email'] ?? null, 'phone' => $b['phone'] ?? null,
            'address' => $b['address'] ?? null, 'commune' => $b['commune'] ?? null, 'is_active' => true, 'created_by' => $user->id,
        ]);
    }

    /** Promesa, escritura o desistimiento: actualiza fechas y el estado de las unidades. */
    public function transition(Reservation $r, string $status, User $user, ?string $date = null, ?string $reason = null): Reservation
    {
        $allowed = ['reserved' => ['promised', 'cancelled'], 'promised' => ['deeded', 'cancelled'], 'deeded' => [], 'cancelled' => []];
        if (! in_array($status, $allowed[$r->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'No se puede pasar de «'.Reservation::STATUSES[$r->status].'» a «'.(Reservation::STATUSES[$status] ?? $status).'».']);
        }

        return DB::transaction(function () use ($r, $status, $user, $date, $reason) {
            $day = $date ?: now()->toDateString();
            $r->update(match ($status) {
                'promised' => ['status' => 'promised', 'promised_at' => $day],
                'deeded' => ['status' => 'deeded', 'deeded_at' => $day],
                'cancelled' => ['status' => 'cancelled', 'cancelled_at' => $day, 'cancel_reason' => $reason],
            });

            Unit::whereIn('id', $r->units()->pluck('unit_id'))->update(['status' => Reservation::UNIT_STATUS[$status]]);

            if ($status === 'cancelled') {
                $r->payments()->where('status', 'pending')->update(['status' => 'cancelled']);
                $r->quote?->update(['status' => 'lost', 'lost_reason' => $reason ? 'Desistió: '.$reason : 'Desistió de la reserva']);
            }

            $label = ['promised' => 'Promesa firmada', 'deeded' => 'Escritura firmada', 'cancelled' => 'Reserva desistida'][$status];
            $this->touchLead($r, $user, "{$label}: {$r->number}".($reason ? " · {$reason}" : ''), $status === 'cancelled' ? null : ($status === 'promised' ? 'Promesado' : 'Escriturado'));

            return $r->fresh(['units', 'payments']);
        });
    }

    /** Registra el pago de una cuota. @param array<string, mixed> $data */
    public function pay(ReservationPayment $p, array $data): ReservationPayment
    {
        $p->update([
            'status' => 'paid',
            'paid_at' => $data['paid_at'] ?? now()->toDateString(),
            'paid_clp' => $data['paid_clp'] ?? ($p->currency === 'CLP' ? $p->amount : null),
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? $p->notes,
        ]);

        return $p;
    }

    /** Deja constancia en el prospecto y lo mueve a la etapa que corresponda (si existe una con ese nombre). */
    private function touchLead(Reservation $r, User $user, string $text, ?string $stageName): void
    {
        $lead = $r->lead_id ? Lead::find($r->lead_id) : null;
        if (! $lead) {
            return;
        }
        app(LeadService::class)->log($lead, 'reservation', $user, $text, ['reservation_id' => $r->id]);
        if (! $lead->client_id) {
            $lead->forceFill(['client_id' => $r->client_id])->saveQuietly();
        }
        if ($stageName && ($stage = PipelineStage::where('name', 'like', $stageName.'%')->first()) && $lead->stage_id !== $stage->id) {
            app(LeadService::class)->move($lead, $stage->id, null, $user);
        }
    }
}
