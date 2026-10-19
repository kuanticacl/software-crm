<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\LimitType;
use App\Models\Plan;
use App\Support\BillingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        $limitTypes = LimitType::orderBy('sort_order')->orderBy('id')->get(['id', 'key', 'name', 'unit', 'description']);

        return Inertia::render('billing/Plans', [
            'plans' => Plan::withCount(['subscriptions as active_subscriptions' => fn ($q) => $q->whereIn('status', ['active', 'past_due'])])
                ->with(['prices', 'limits'])->orderBy('sort_order')->orderBy('id')->get()->map(fn (Plan $p) => [
                    'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'tagline' => $p->tagline, 'description' => $p->description,
                    'features' => $p->features ?? [], 'is_active' => $p->is_active, 'is_public' => $p->is_public, 'active_subscriptions' => $p->active_subscriptions,
                    'prices' => $p->prices->mapWithKeys(fn ($pr) => [$pr->period => ['amount' => (int) $pr->amount, 'is_active' => $pr->is_active]])->all(),
                    'limits' => $p->limits->mapWithKeys(fn (LimitType $l) => [$l->id => $l->pivot->value === null ? null : (int) $l->pivot->value])->all(),
                ]),
            'limitTypes' => $limitTypes,
            'periods' => BillingPeriod::LABELS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = $this->save(new Plan, $request);
        $this->toast("Plan «{$plan->name}» creado.");

        return back();
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $this->save($plan, $request);
        $this->toast('Plan actualizado.');

        return back();
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->subscriptions()->whereIn('status', ['active', 'past_due', 'suspended'])->exists()) {
            $this->toast('Hay suscripciones vigentes con este plan: desactívalo en vez de eliminarlo.', 'error');

            return back();
        }
        $plan->delete();
        $this->toast('Plan eliminado.');

        return back();
    }

    private function save(Plan $plan, Request $request): Plan
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'features' => ['nullable', 'array', 'max:30'],
            'features.*' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'prices' => ['nullable', 'array'],
            'prices.*.amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'prices.*.is_active' => ['nullable', 'boolean'],
            'limits' => ['nullable', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ], ['name.required' => 'Ponle un nombre al plan.']);

        return DB::transaction(function () use ($plan, $data) {
            $plan->fill([
                'name' => $data['name'], 'tagline' => $data['tagline'] ?? null, 'description' => $data['description'] ?? null,
                'features' => array_values(array_filter($data['features'] ?? [])) ?: null,
                'is_active' => $data['is_active'] ?? true, 'is_public' => $data['is_public'] ?? true,
            ]);
            if (! $plan->exists) {
                $plan->slug = $this->uniqueSlug($data['name']);
                $plan->sort_order = (int) Plan::withTrashed()->max('sort_order') + 1;
            }
            $plan->save();

            foreach (BillingPeriod::LABELS as $period => $_) {
                $row = $data['prices'][$period] ?? null;
                $amount = $row['amount'] ?? null;
                if ($amount === null || $amount === '') {
                    $plan->prices()->where('period', $period)->delete();

                    continue;
                }
                $plan->prices()->updateOrCreate(['period' => $period], ['amount' => (int) $amount, 'is_active' => (bool) ($row['is_active'] ?? true)]);
            }

            // Límites: id del tipo → valor (vacío = ilimitado); solo los tipos presentes en el formulario.
            $sync = [];
            foreach (LimitType::pluck('id') as $id) {
                if (array_key_exists($id, $data['limits'] ?? [])) {
                    $v = $data['limits'][$id];
                    $sync[$id] = ['value' => ($v === null || $v === '') ? null : (int) $v];
                }
            }
            $plan->limits()->sync($sync);

            return $plan;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;
        for ($i = 2; Plan::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    // ---------------------------------------------------------------- tipos de límite

    public function storeLimit(Request $request): RedirectResponse
    {
        $data = $this->limitData($request);
        LimitType::create([...$data, 'key' => $this->limitKey($data['name']), 'sort_order' => (int) LimitType::max('sort_order') + 1]);
        $this->toast('Límite creado. Ya puedes asignarle un valor en cada plan.');

        return back();
    }

    public function updateLimit(Request $request, LimitType $limit): RedirectResponse
    {
        $limit->update($this->limitData($request));
        $this->toast('Límite actualizado.');

        return back();
    }

    public function destroyLimit(LimitType $limit): RedirectResponse
    {
        $limit->delete();
        $this->toast('Límite eliminado de todos los planes.');

        return back();
    }

    /** @return array<string, mixed> */
    private function limitData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:300'],
        ], ['name.required' => 'Ponle un nombre al límite.']);
    }

    private function limitKey(string $name): string
    {
        $base = str_replace('-', '_', Str::slug($name)) ?: 'limite';
        $key = $base;
        for ($i = 2; LimitType::where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
