<?php

namespace App\Http\Requests;

use App\Enums\AbuseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'abuse_type' => ['required', Rule::enum(AbuseType::class)],
            'target_ip' => ['nullable', 'ip'],
            'target_domain' => ['nullable', 'string', 'max:255'],
            'target_url' => ['nullable', 'url', 'max:2048'],
            'description' => ['required', 'string', 'max:10000'],
            'evidence' => ['nullable', 'string', 'max:50000'],
            'reported_at' => ['nullable', 'date'],
            'headers' => ['nullable', 'array'],
        ];
    }
}
