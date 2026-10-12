<?php

namespace App\Services\RealEstate;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Unit;
use App\Models\User;
use App\Services\LeadService;
use App\Services\UfService;
use App\Support\Rut;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Arma y guarda cotizaciones. Los precios y totales se recalculan siempre en el servidor. */
class QuoteBuilder
{
    /** Datos del cotizante sugeridos desde el cliente o el prospecto. @return array<string, string|null> */
    public static function buyerFor(?Client $client, ?Lead $lead): array
    {
        return [
            'name' => $client?->name ?: $lead?->full_name,
            'rut' => $client?->tax_id,
            'email' => $client?->email ?: $lead?->email,
            'phone' => $client?->phone ?: $lead?->phone,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?Quote $quote, User $user): Quote
    {
        return DB::transaction(function () use ($data, $quote, $user) {
            $project = Project::findOrFail($data['project_id']);
            $quote ??= new Quote(['status' => 'draft', 'user_id' => $user->id]);

            $rows = collect($data['items'] ?? [])->values();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Agrega al menos una unidad a la cotización.']);
            }

            $units = Unit::where('project_id', $project->id)->whereIn('id', $rows->pluck('unit_id'))->get()->keyBy('id');
            $keep = $quote->exists ? $quote->items()->pluck('unit_id')->filter()->all() : [];
            $maxDiscount = $user->hasPermission('quotes.discount') ? 100 : (float) $project->max_discount_pct;

            $items = $rows->map(function ($row, $n) use ($units, $keep, $maxDiscount) {
                $unit = $units->get((int) $row['unit_id']);
                if (! $unit) {
                    throw ValidationException::withMessages(["items.{$n}.unit_id" => 'La unidad no existe o no pertenece al proyecto.']);
                }
                // Se puede cotizar lo disponible; lo que ya estaba en la cotización se mantiene aunque haya cambiado de estado.
                if ($unit->status !== 'disponible' && ! in_array($unit->id, $keep, true)) {
                    throw ValidationException::withMessages(["items.{$n}.unit_id" => "{$unit->label()} no está disponible (".Unit::STATUSES[$unit->status].').']);
                }
                $discount = round((float) ($row['discount_pct'] ?? 0), 2);
                if ($discount > $maxDiscount) {
                    throw ValidationException::withMessages(["items.{$n}.discount_pct" => 'El descuento máximo autorizado en este proyecto es '.rtrim(rtrim(number_format($maxDiscount, 2, ',', ''), '0'), ',').'%.']);
                }

                return ['unit' => $unit, 'list_price_uf' => $unit->list_price, 'discount_pct' => $discount];
            });

            $piePct = (float) ($data['pie_pct'] ?? $project->default_pie_pct);
            $rate = (float) ($data['mortgage_rate'] ?? $project->mortgage_rate);
            $years = (int) ($data['mortgage_years'] ?? $project->mortgage_years);
            $t = QuoteCalculator::totals($items->map(fn ($i) => ['list_price_uf' => $i['list_price_uf'], 'discount_pct' => $i['discount_pct']])->all(), $piePct, $rate, $years);

            $client = ! empty($data['client_id']) ? Client::find($data['client_id']) : null;
            $lead = ! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null;
            $buyer = collect($data['buyer'] ?? [])->map(fn ($v) => is_string($v) ? trim($v) : $v)->filter(fn ($v) => $v !== null && $v !== '')->all()
                ?: array_filter(self::buyerFor($client, $lead));
            if (! empty($buyer['rut'])) {
                $buyer['rut'] = Rut::format($buyer['rut']);
            }

            $quote->fill([
                'project_id' => $project->id,
                'lead_id' => $lead?->id,
                'client_id' => $client?->id,
                'buyer' => $buyer,
                'valid_until' => $data['valid_until'] ?? now()->addDays($project->quote_validity_days)->toDateString(),
                'pie_pct' => $piePct,
                'pie_installments' => (int) ($data['pie_installments'] ?? $project->default_pie_installments),
                'reservation_amount' => (float) ($data['reservation_amount'] ?? $project->reservation_amount),
                'mortgage_rate' => $rate,
                'mortgage_years' => $years,
                'notes' => $data['notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                ...collect($t)->except('items')->all(),
            ]);
            if (! empty($data['user_id']) && $user->hasPermission('quotes.view_all')) {
                $quote->user_id = (int) $data['user_id'];
            }
            $this->snapshotUf($quote);
            $isNew = ! $quote->exists;
            $quote->save();

            $quote->items()->delete();
            foreach ($items as $n => $i) {
                $quote->items()->create([
                    'unit_id' => $i['unit']->id,
                    'type' => $i['unit']->type,
                    'code' => $i['unit']->code,
                    'model' => $i['unit']->model,
                    'detail' => $i['unit']->detail() ?: null,
                    'list_price_uf' => $i['list_price_uf'],
                    'discount_pct' => $i['discount_pct'],
                    'price_uf' => $t['items'][$n],
                    'sort_order' => $n,
                ]);
            }

            if ($isNew && $lead) {
                app(LeadService::class)->log($lead, 'quote', $user, "Cotización {$quote->number} · {$project->name} · ".number_format($quote->total_uf, 2, ',', '.').' UF', ['quote_id' => $quote->id]);
            }

            return $quote->load('items');
        });
    }

    /** UF del día mientras es borrador; al enviarla queda congelada. */
    public function snapshotUf(Quote $quote): void
    {
        if ($quote->exists && $quote->status !== 'draft') {
            return;
        }
        if ($uf = app(UfService::class)->today()) {
            $quote->uf_value = $uf['value'];
            $quote->uf_date = $uf['date'];
        }
    }
}
