<?php

namespace App\Http\Controllers;

use App\Models\LeadField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadFieldController extends Controller
{
    /** Claves que ya existen como columnas y no pueden reutilizarse. */
    private const RESERVED = [
        'first_name', 'last_name', 'email', 'phone', 'job_title', 'company', 'message', 'source', 'stage',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'custom', 'meta',
    ];

    public function index(): Response
    {
        return Inertia::render('crm/Fields', [
            'fields' => LeadField::orderBy('sort_order')->orderBy('id')->get(),
            'types' => LeadField::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['key'] = $this->uniqueKey($request->input('key') ?: $data['label']);
        $data['sort_order'] = (int) LeadField::max('sort_order') + 1;

        $field = LeadField::create($data);

        $this->toast("Campo «{$field->label}» creado.");

        return back();
    }

    public function update(Request $request, LeadField $field): RedirectResponse
    {
        // La clave (key) no cambia: los datos ya guardados dependen de ella.
        $field->update($this->validated($request));

        $this->toast("Campo «{$field->label}» actualizado.");

        return back();
    }

    public function reorder(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:lead_fields,id'],
        ])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                LeadField::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return back();
    }

    public function destroy(LeadField $field): RedirectResponse
    {
        $field->delete(); // los valores ya guardados en leads.custom se conservan

        $this->toast('Campo eliminado. Los datos ya guardados en los prospectos se conservan.');

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(LeadField::TYPES))],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:80'],
            'is_required' => ['boolean'],
            'show_on_card' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $data['options'] = $data['type'] === 'select'
            ? array_values(array_filter(array_map('trim', $data['options'] ?? []), fn ($o) => $o !== ''))
            : null;

        if ($data['type'] === 'select' && empty($data['options'])) {
            throw ValidationException::withMessages(['options' => 'Agrega al menos una opción para la lista.']);
        }

        return $data;
    }

    private function uniqueKey(string $source): string
    {
        $base = Str::slug($source, '_') ?: 'campo';
        $key = $base;
        $i = 2;

        while (in_array($key, self::RESERVED, true) || LeadField::where('key', $key)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }
}
