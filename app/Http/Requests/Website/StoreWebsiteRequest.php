<?php

namespace App\Http\Requests\Website;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebsiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Authorization is handled by the #[Authorize] attribute on the controller action.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** @example My website */
            'name' => ['required', 'string', 'max:255'],
            /** @example example.com */
            'domain' => [
                'required',
                'string',
                'max:255',
                Rule::unique('websites', 'domain')
                    ->where(fn ($query) => $query->where('subdomain', $this->input('subdomain') ?? ''))
                    ->withoutTrashed(),
            ],
            // 'nullable' is required because Laravel's ConvertEmptyStringsToNull middleware
            // turns an explicit subdomain: "" (meaning "root domain, no subdomain") into null
            // before validation runs.
            'subdomain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
