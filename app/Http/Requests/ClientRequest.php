<?php

namespace App\Http\Requests;

use App\Support\ProposalText;
use App\Support\Rut;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la autorización se aplica en las rutas (can:clients.*)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(\App\Models\Client::KINDS))],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'activity' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20', function ($attr, $value, $fail) {
                if ($value && ! Rut::isValid($value)) {
                    $fail('El RUT no es válido (revisa el dígito verificador).');
                }
            }],
            'commune' => ['nullable', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_role' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        if (! empty($data['tax_id'])) {
            $data['tax_id'] = Rut::format($data['tax_id']); // siempre 76.123.456-7
        }

        if (! empty($data['notes'])) {
            $data['notes'] = ProposalText::sanitize((string) $data['notes']);
        }

        return $key ? data_get($data, $key, $default) : $data;
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'activity' => 'giro', 'commune' => 'comuna', 'contact_name' => 'contacto', 'legal_name' => 'razón social', 'tax_id' => 'RUT', 'website' => 'sitio web', 'address' => 'dirección', 'city' => 'ciudad', 'notes' => 'notas'];
    }
}
