<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
            /** @example user@example.com */
            'email' => ['required', 'email'],
            /** @example your-password */
            'password' => ['required', 'string'],
            /** @example Insomnia */
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
