<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthLoginRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string|min:6',

            'guest_cart_id' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.exists' => 'These credentials do not match our records.',

            'guest_cart_id.uuid' => 'The provided guest cart identifier is invalid.',
        ];
    }
}
