<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Agency;
use App\Support\Rut;
use App\Support\TaxDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Datos legales, de contacto y redes de la agencia (firma y pie de las propuestas). */
class AgencyController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('crm/Agency', [
            'agency' => [...Agency::profile(), 'tax_document' => TaxDocument::default()],
            'socials' => Agency::SOCIALS,
            'holding' => Agency::holding(),
            'taxDocuments' => TaxDocument::MODES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $url = ['nullable', 'url:http,https', 'max:300'];
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v && ! Rut::isValid($v) ? $fail('El RUT no es válido.') : null],
            'address' => ['required', 'string', 'max:300'],
            'email' => ['required', 'email:rfc', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['required', 'url:http,https', 'max:300'],
            'tax_document' => ['required', 'in:'.implode(',', array_keys(TaxDocument::MODES))],
            'instagram' => $url, 'linkedin' => $url, 'facebook' => $url, 'youtube' => $url, 'tiktok' => $url,
        ], ['url' => 'Ingresa una URL completa que empiece con https://']);

        $data['tax_id'] = Rut::format($data['tax_id'] ?? null);
        foreach (array_keys(Agency::FIELDS) as $key) {
            Setting::put("agency.{$key}", $data[$key] ?? null);
        }

        Setting::put('billing.tax_document', $data['tax_document']);

        $this->toast('Datos de la agencia guardados.');

        return back();
    }
}
