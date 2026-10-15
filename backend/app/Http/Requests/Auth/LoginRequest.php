<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the POST /api/v1/auth/login payload.
 *
 * Unauthenticated requests are always permitted; authorisation is
 * handled by the authentication logic in {@see \App\Http\Controllers\API\AuthController}.
 */
class LoginRequest extends FormRequest
{
    /**
     * Always permit — auth routes are public.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required_without:login', 'string', 'max:255'],
            'login'    => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'An email address or employee number is required.',
            'password.required' => 'A password is required.',
            'password.min'      => 'Password must be at least 6 characters.',
        ];
    }
}
