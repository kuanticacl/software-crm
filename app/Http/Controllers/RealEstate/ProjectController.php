<?php

namespace App\Http\Controllers\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Quote;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use App\Services\RealEstate\ImageStore;
use App\Services\UfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Proyectos inmobiliarios: ficha, condiciones comerciales, ejecutivos asignados y resumen del inventario. */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $projects = Project::visibleTo($user)->with('executives:id,name')->orderBy('sort_order')->orderBy('name')->get();
        $stock = $this->stockByProject($projects->pluck('id')->all());

        return Inertia::render('realestate/projects/Index', [
            'projects' => $projects->map(fn (Project $p) => [
                ...$this->card($p),
                'stock' => $stock[$p->id] ?? ['total' => 0, 'by_status' => [], 'available_main' => 0, 'from_uf' => null],
            ]),
            'statuses' => Unit::STATUSES,
            'statusColors' => Unit::STATUS_COLORS,
            'can' => ['manage' => $user->hasPermission('projects.manage')],
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorizeView($request, $project);
        $user = $request->user();
        $project->load('executives:id,name,email');
        $units = $project->units()->orderBy('tower')->orderByDesc('floor')->orderBy('code')->get();

        $since = now()->subDays(30);

        return Inertia::render('realestate/projects/Show', [
            'project' => [...$this->card($project), ...$project->only(['address', 'sales_office', 'description', 'website_url', 'brochure_url', 'reservation_amount', 'default_pie_pct', 'default_pie_installments', 'max_discount_pct', 'mortgage_rate', 'mortgage_years', 'quote_validity_days', 'promise_days'])],
            'units' => $units->map(fn (Unit $u) => [
                'id' => $u->id, 'type' => $u->type, 'code' => $u->code, 'tower' => $u->tower, 'model' => $u->model, 'floor' => $u->floor,
                'program' => $u->programLabel(), 'surface_useful' => $u->surface_useful, 'surface_total' => $u->surface_total, 'list_price' => $u->list_price, 'status' => $u->status,
            ]),
            'kpis' => [
                'quotes_30d' => Quote::where('project_id', $project->id)->where('created_at', '>=', $since)->count(),
                'reservations_30d' => Reservation::where('project_id', $project->id)->where('status', '!=', 'cancelled')->where('reserved_at', '>=', $since->toDateString())->count(),
                'sold_uf' => (float) Reservation::where('project_id', $project->id)->whereIn('status', ['promised', 'deeded'])->sum('total_uf'),
                'reserved_uf' => (float) Reservation::where('project_id', $project->id)->where('status', 'reserved')->sum('total_uf'),
                'stock_uf' => (float) $units->where('status', 'disponible')->sum('list_price'),
            ],
            'uf' => app(UfService::class)->today(),
            'types' => Unit::TYPES,
            'statuses' => Unit::STATUSES,
            'statusColors' => Unit::STATUS_COLORS,
            'can' => [
                'manage' => $user->hasPermission('projects.manage'),
                'units' => $user->hasPermission('units.manage'),
                'quote' => $user->hasPermission('quotes.create'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('realestate/projects/Form', ['project' => null, 'users' => $this->users(), 'stages' => Project::STAGES]);
    }

    public function edit(Project $project): Response
    {
        $project->load('executives:id');

        return Inertia::render('realestate/projects/Form', [
            'project' => [
                ...$project->only(['id', 'name', 'developer', 'commune', 'city', 'address', 'sales_office', 'description', 'stage', 'delivery_note', 'website_url', 'brochure_url', 'color',
                    'reservation_amount', 'default_pie_pct', 'default_pie_installments', 'max_discount_pct', 'mortgage_rate', 'mortgage_years', 'quote_validity_days', 'promise_days', 'quote_conditions', 'is_active']),
                'delivery_date' => $project->delivery_date?->toDateString(),
                'cover' => $project->coverUrl(),
                'executive_ids' => $project->executives->pluck('id'),
                'lead_ids' => $project->executives->where('pivot.is_lead', true)->pluck('id')->values(),
            ],
            'users' => $this->users(),
            'stages' => Project::STAGES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $project = Project::create([...$data, 'sort_order' => (int) Project::max('sort_order') + 1]);
            $this->saveCover($request, $project);
            $this->syncExecutives($project, $request);

            return $project;
        });
        $this->toast("Proyecto «{$project->name}» creado. Ahora carga su inventario.");

        return redirect("/projects/{$project->id}");
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        DB::transaction(function () use ($request, $project) {
            $project->update($this->validated($request));
            $this->saveCover($request, $project);
            $this->syncExecutives($project, $request);
        });
        $this->toast('Proyecto actualizado.');

        return redirect("/projects/{$project->id}");
    }

    public function destroy(Project $project): RedirectResponse
    {
        abort_if($project->reservations()->where('status', '!=', 'cancelled')->exists(), 422, 'El proyecto tiene reservas activas: no se puede eliminar.');
        $project->delete();
        $this->toast('Proyecto eliminado.');

        return redirect('/projects');
    }

    // ------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function card(Project $p): array
    {
        return [
            ...$p->only(['id', 'name', 'slug', 'developer', 'commune', 'city', 'stage', 'delivery_note', 'color', 'is_active']),
            'stage_label' => Project::STAGES[$p->stage] ?? $p->stage,
            'delivery_date' => $p->delivery_date?->toDateString(),
            'location' => $p->location(),
            'cover' => $p->coverUrl(),
            'executives' => $p->executives->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'is_lead' => (bool) $u->pivot?->is_lead])->values(),
        ];
    }

    /** @param array<int, int> $ids @return array<int, array<string, mixed>> */
    private function stockByProject(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = Unit::whereIn('project_id', $ids)->selectRaw('project_id, status, count(*) c')->groupBy('project_id', 'status')->get();
        $main = Unit::whereIn('project_id', $ids)->whereIn('type', Unit::MAIN_TYPES)->where('status', 'disponible')
            ->selectRaw('project_id, count(*) c, min(list_price) from_uf')->groupBy('project_id')->get()->keyBy('project_id');
        $out = [];
        foreach ($rows as $r) {
            $out[$r->project_id]['by_status'][$r->status] = (int) $r->c;
        }
        foreach ($ids as $id) {
            $out[$id] = [
                'by_status' => $out[$id]['by_status'] ?? [],
                'total' => array_sum($out[$id]['by_status'] ?? []),
                'available_main' => (int) ($main[$id]->c ?? 0),
                'from_uf' => isset($main[$id]) ? (float) $main[$id]->from_uf : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'developer' => ['nullable', 'string', 'max:160'],
            'commune' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:300'],
            'sales_office' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'stage' => ['required', 'in:'.implode(',', array_keys(Project::STAGES))],
            'delivery_date' => ['nullable', 'date'],
            'delivery_note' => ['nullable', 'string', 'max:120'],
            'website_url' => ['nullable', 'url:http,https', 'max:300'],
            'brochure_url' => ['nullable', 'url:http,https', 'max:300'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'reservation_amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'default_pie_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'default_pie_installments' => ['required', 'integer', 'min:0', 'max:120'],
            'max_discount_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'mortgage_rate' => ['required', 'numeric', 'min:0', 'max:30'],
            'mortgage_years' => ['required', 'integer', 'min:1', 'max:40'],
            'quote_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'promise_days' => ['required', 'integer', 'min:1', 'max:365'],
            'quote_conditions' => ['nullable', 'string', 'max:6000'],
            'is_active' => ['boolean'],
            'cover' => ['nullable', ...ImageStore::RULES],
            'executive_ids' => ['nullable', 'array'],
            'executive_ids.*' => ['integer', 'exists:users,id'],
            'lead_ids' => ['nullable', 'array'],
            'lead_ids.*' => ['integer'],
        ], ['name.required' => 'Indica el nombre del proyecto.', 'website_url.url' => 'Ingresa una URL completa (https://…).', 'brochure_url.url' => 'Ingresa una URL completa (https://…).']);

        unset($data['cover'], $data['executive_ids'], $data['lead_ids']);
        $data['color'] ??= '#6419DB';

        return $data;
    }

    private function saveCover(Request $request, Project $project): void
    {
        if ($request->hasFile('cover')) {
            $old = $project->cover_path;
            $project->update(['cover_path' => ImageStore::store($request->file('cover'), 'projects', 2000)]);
            ImageStore::delete($old);
        } elseif ($request->boolean('remove_cover')) {
            ImageStore::delete($project->cover_path);
            $project->update(['cover_path' => null]);
        }
    }

    private function syncExecutives(Project $project, Request $request): void
    {
        $leads = collect($request->input('lead_ids', []))->map(fn ($v) => (int) $v)->all();
        $project->executives()->sync(collect($request->input('executive_ids', []))->mapWithKeys(fn ($id) => [(int) $id => ['is_lead' => in_array((int) $id, $leads, true)]])->all());
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function users()
    {
        return User::query()->whereHas('role', fn ($q) => $q->staff())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']);
    }

    private function authorizeView(Request $request, Project $project): void
    {
        abort_unless(Project::visibleTo($request->user())->whereKey($project->id)->exists(), 403, 'No eres ejecutivo/a de este proyecto.');
    }
}
