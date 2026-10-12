<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'client_service_id' => ['nullable', 'integer', Rule::exists('client_services', 'id')->whereNull('deleted_at')],
            'number' => ['nullable', 'string', 'max:60'],
            'concept' => ['required', 'string', 'max:255'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['required', 'date'],
            'currency' => ['required', Rule::in(['CLP', 'UF'])],
            'amount_net' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'document_type' => ['required', Rule::in(array_keys(\App\Models\Invoice::DOCUMENTS))],
            'kind' => ['required', Rule::in(array_keys(\App\Models\Invoice::KINDS))],
            'retention_rate' => ['nullable', 'numeric', 'between:0,100'],
            'retention_by_client' => ['boolean'],
            'auto_remind' => ['boolean'],
            'payment_link' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'issue' => ['boolean'],
            'paid' => ['boolean'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'payment_reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['paid_at', 'payment_method', 'payment_reference', 'client_service_id', 'number', 'period_start', 'period_end', 'issue_date', 'payment_link', 'notes', 'tax_rate', 'retention_rate'] as $k) {
            if ($this->input($k) === '') {
                $this->merge([$k => null]);
            }
        }
    }
}
