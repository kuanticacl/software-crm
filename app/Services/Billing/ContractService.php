<?php

namespace App\Services\Billing;

use App\Models\ClientService;
use App\Models\ServiceExpense;
use App\Models\User;
use App\Services\UfService;

class ContractService
{
    public function __construct(private InvoiceService $invoices) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ?User $actor = null): ClientService
    {
        $plan = $data['payment_plan'] ?? [];
        unset($data['payment_plan']);

        $service = new ClientService([...$data, 'created_by' => $actor?->id]);
        $this->syncSchedule($service, true);
        $service->save();

        // Plan de pagos: un cobro «por emitir» por hito (honorarios → boleta; obra → otro documento).
        foreach ($plan as $hito) {
            $this->invoices->create([
                'client_id' => $service->client_id,
                'client_service_id' => $service->id,
                'kind' => $hito['kind'],
                'document_type' => $hito['kind'] === 'obra' ? 'otro' : 'boleta',
                'concept' => $service->name.' · '.$hito['concept'],
                'due_date' => $hito['due_date'],
                'currency' => $service->currency,
                'amount_net' => $hito['amount'],
                'auto_remind' => false,
                'payment_link' => $service->payment_link,
            ], $actor);
        }

        return $service;
    }

    /** @param  array<string, mixed>  $data */
    public function update(ClientService $service, array $data): ClientService
    {
        unset($data['payment_plan']);
        $service->fill($data);
        $reschedule = $service->isDirty(['billing_cycle', 'billing_day', 'start_date', 'status']);
        $this->syncSchedule($service, $reschedule);
        $service->save();

        return $service;
    }

    /**
     * Define la próxima fecha de cobro. Los cobros comienzan desde hoy: no se crean cobros de períodos pasados
     * (los históricos se registran a mano). Un servicio pausado, finalizado, por iniciar sin fecha o de pago único no programa cobros.
     */
    public function syncSchedule(ClientService $s, bool $recompute): void
    {
        if (! $s->isRecurring() || in_array($s->status, ['blocked', 'cancelled'], true)) {
            $s->next_charge_on = null;

            return;
        }
        if (! $recompute && $s->next_charge_on) {
            return;
        }

        $next = $s->firstChargeDate();
        $guard = 0;
        while ($next->lt(today()) && $guard++ < 600) {
            $next = $s->addPeriod($next);
        }
        $s->next_charge_on = $next;
    }

    public function addExpense(ClientService $s, array $data, ?User $actor = null): ServiceExpense
    {
        $currency = $data['currency'] ?? 'CLP';
        $amount = (float) $data['amount'];
        $date = $data['incurred_on'] ?? today();
        $clp = $currency === 'UF' ? round($amount * (app(UfService::class)->forDate($date) ?? 0)) : round($amount);

        return $s->expenses()->create([...$data, 'currency' => $currency, 'amount_clp' => $clp, 'created_by' => $actor?->id]);
    }

    /**
     * Resumen interno de rentabilidad en pesos: facturado neto (sin anuladas ni por emitir), cobrado, gastos y margen.
     *
     * @return array{billed: float, paid: float, expenses: float, margin: float}
     */
    public function financials(ClientService $s): array
    {
        $billed = (float) $s->invoices()->whereIn('status', ['issued', 'paid'])->sum('net_clp');
        $paid = (float) $s->invoices()->where('status', 'paid')->sum('net_clp');
        $expenses = (float) $s->expenses()->sum('amount_clp');

        return ['billed' => $billed, 'paid' => $paid, 'expenses' => $expenses, 'margin' => $billed - $expenses];
    }
}
