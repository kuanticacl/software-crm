<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Unit;
use App\Services\RealEstate\ImageStore;
use App\Services\RealEstate\UnitImporter;
use App\Services\UfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Inventario de unidades por proyecto: precios en UF, plantas, superficies, programa y estado de venta. */
class UnitController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $projects = Project::visibleTo($user)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color', 'max_discount_pct']);
        $project = $projects->firstWhere('id', (int) $request->query('project')) ?? $projects->first();

        $units = $project
            ? Unit::where('project_id', $project->id)->orderBy('type')->orderBy('tower')->orderBy('floor')->orderBy('code')->get()
            : collect();

        return Inertia::render('realestate/units/Index', [
            'projects' => $projects,
            'project' => $project?->only(['id', 'name', 'color']),
            'units' => $units->map(fn (Unit $u) => $this->row($u))->values(),
            'models' => $units->pluck('model')->filter()->unique()->sort()->values(),
            'filters' => $request->only(['type', 'status', 'q']),
            'uf' => app(UfService::class)->today(),
            'types' => Unit::TYPES,
            'mainTypes' => Unit::MAIN_TYPES,
            'statuses' => Unit::STATUSES,
            'statusColors' => Unit::STATUS_COLORS,
            'orientations' => Unit::ORIENTATIONS,
            'can' => [
                'manage' => $user->hasPermission('units.manage'),
                'status' => $user->hasPermission('units.status'),
                'quote' => $user->hasPermission('quotes.create'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->authorizeProject($request, (int) $data['project_id']);
        $unit = Unit::create($data);
        $this->savePlan($request, $unit);
        $this->toast("{$unit->label()} agregada al inventario.");

        return back();
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeProject($request, $unit->project_id);
        $data = $this->validated($request, $unit);
        unset($data['project_id']);
        $unit->update($data);
        $this->savePlan($request, $unit);
        $this->toast("{$unit->label()} actualizada.");

        return back();
    }

    public function destroy(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeProject($request, $unit->project_id);
        abort_unless(in_array($unit->status, ['disponible', 'bloqueada'], true), 422, 'La unidad está reservada o vendida: no se puede eliminar.');
        $unit->delete();
        $this->toast("{$unit->label()} eliminada del inventario.");

        return back();
    }

    /** Bloquear o liberar (las reservas y ventas cambian el estado solas). */
    public function status(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeProject($request, $unit->project_id);
        $data = $request->validate(['status' => ['required', 'in:disponible,bloqueada']]);
        abort_unless(in_array($unit->status, ['disponible', 'bloqueada'], true), 422, 'La unidad tiene una reserva o venta: cambia su estado desde la reserva.');
        $unit->update($data);
        $this->toast($data['status'] === 'bloqueada' ? "{$unit->label()} bloqueada." : "{$unit->label()} liberada.");

        return back();
    }

    public function removePlan(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeProject($request, $unit->project_id);
        $path = $unit->floor_plan_path;
        $unit->update(['floor_plan_path' => null]);
        if ($path && ! Unit::where('floor_plan_path', $path)->exists()) {
            ImageStore::delete($path);
        }
        $this->toast('Planta quitada.');

        return back();
    }

    /** Ajuste masivo de precios (lista de precios nueva): % o monto en UF sobre las unidades elegidas. */
    public function prices(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($request, $project->id);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'mode' => ['required', 'in:percent,amount,set'],
            'value' => ['required', 'numeric', 'min:-100000', 'max:1000000'],
        ]);
        $units = Unit::where('project_id', $project->id)->whereIn('id', $data['ids'])->get();
        foreach ($units as $u) {
            $price = match ($data['mode']) {
                'percent' => $u->list_price * (1 + $data['value'] / 100),
                'amount' => $u->list_price + $data['value'],
                'set' => $data['value'],
            };
            $u->update(['list_price' => round(max(0, $price), 2)]);
        }
        $this->toast("Precios actualizados en {$units->count()} unidades.");

        return back();
    }

    public function import(Request $request, Project $project, UnitImporter $importer): RedirectResponse
    {
        $this->authorizeProject($request, $project->id);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'], 'update_prices' => ['boolean']], ['file.mimes' => 'Sube un archivo CSV (en Excel: Archivo → Guardar como → CSV).']);
        $res = $importer->import($project, $request->file('file')->getRealPath(), $request->boolean('update_prices', true));

        $msg = "Inventario importado: {$res['created']} nuevas, {$res['updated']} actualizadas".($res['skipped'] ? ", {$res['skipped']} sin cambios" : '').'.';
        $this->toast($res['errors'] && ! $res['created'] && ! $res['updated'] ? $res['errors'][0] : $msg, $res['errors'] && ! $res['created'] && ! $res['updated'] ? 'error' : 'success');

        return back()->with('import_errors', array_slice($res['errors'], 0, 20));
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, UnitImporter::TEMPLATE, ';');
            foreach ([
                ['departamento', '201', 'A', 'A1', '2', 'Norte', '2', '2', '2D+2B', '58,40', '8,20', '66,60', '', '3.450', 'disponible'],
                ['departamento', '202', 'A', 'B', '2', 'Oriente', '3', '2', '3D+2B', '72,10', '10,50', '82,60', '', '4.180', 'disponible'],
                ['estacionamiento', 'E-01', '', '', '-1', '', '', '', '', '12,50', '', '', '', '320', 'disponible'],
                ['bodega', 'B-01', '', '', '-1', '', '', '', '', '4,00', '', '', '', '90', 'disponible'],
            ] as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, 'inventario-ejemplo.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function row(Unit $u): array
    {
        return [
            ...$u->only(['id', 'project_id', 'type', 'code', 'tower', 'model', 'floor', 'orientation', 'bedrooms', 'bathrooms', 'program', 'surface_useful', 'surface_terrace', 'surface_total', 'surface_lot', 'list_price', 'status', 'notes']),
            'features' => $u->features ?? [],
            'program_label' => $u->programLabel(),
            'is_main' => $u->isMain(),
            'floor_plan' => $u->floorPlanUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Unit $unit = null): array
    {
        $projectId = (int) ($unit?->project_id ?? $request->input('project_id'));
        $data = $request->validate([
            'project_id' => [$unit ? 'nullable' : 'required', 'integer', 'exists:projects,id'],
            'type' => ['required', 'in:'.implode(',', array_keys(Unit::TYPES))],
            'code' => ['required', 'string', 'max:40', Rule::unique('units')->where(fn ($q) => $q->where('project_id', $projectId)->where('type', $request->input('type'))->whereNull('deleted_at'))->ignore($unit?->id)],
            'tower' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:60'],
            'floor' => ['nullable', 'integer', 'min:-10', 'max:200'],
            'orientation' => ['nullable', 'string', 'max:20'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'program' => ['nullable', 'string', 'max:60'],
            'surface_useful' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'surface_terrace' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'surface_total' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'surface_lot' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'list_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'status' => ['nullable', 'in:disponible,bloqueada'],
            'features' => ['nullable', 'array', 'max:20'],
            'features.*' => ['string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'floor_plan' => ['nullable', ...\App\Services\RealEstate\ImageStore::RULES],
            'plan_for_model' => ['boolean'],
        ], ['code.unique' => 'Ya existe una unidad de ese tipo con ese número en el proyecto.', 'list_price.required' => 'Indica el precio de lista en UF.']);

        // Reservas y ventas mueven el estado: aquí solo disponible ↔ bloqueada.
        if ($unit && ! in_array($unit->status, ['disponible', 'bloqueada'], true)) {
            unset($data['status']);
        }
        if (empty($data['status'])) {
            unset($data['status']);
        }
        $data['features'] = array_values(array_filter(array_map('trim', $data['features'] ?? []))) ?: null;
        unset($data['floor_plan'], $data['plan_for_model']);

        return $data;
    }

    /** Sube la planta; con «plan_for_model» se aplica a todas las unidades del mismo modelo del proyecto. */
    private function savePlan(Request $request, Unit $unit): void
    {
        if (! $request->hasFile('floor_plan')) {
            return;
        }
        $path = ImageStore::store($request->file('floor_plan'), 'floor-plans/'.$unit->project_id, 2200, true);
        $targets = $request->boolean('plan_for_model') && $unit->model
            ? Unit::where('project_id', $unit->project_id)->where('model', $unit->model)->get()
            : collect([$unit]);
        $old = $targets->pluck('floor_plan_path')->filter()->unique();
        Unit::whereIn('id', $targets->pluck('id'))->update(['floor_plan_path' => $path]);
        foreach ($old as $p) {
            if (! Unit::where('floor_plan_path', $p)->exists()) {
                ImageStore::delete($p);
            }
        }
    }

    private function authorizeProject(Request $request, int $projectId): void
    {
        abort_unless(Project::visibleTo($request->user())->whereKey($projectId)->exists(), 403, 'No tienes acceso a ese proyecto.');
    }
}
