<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'company_share' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'profile_image' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Le prénom est obligatoire',
            'last_name.required' => 'Le nom de famille est obligatoire',
            'username.required' => 'Le nom d\'utilisateur est obligatoire',
            'username.unique' => 'Ce nom d\'utilisateur est déjà utilisé',
            'email.required' => 'L\'email est obligatoire',
            'email.email' => 'L\'email doit être valide',
            'email.unique' => 'Cet email est déjà utilisé',
            'password.required' => 'Le mot de passe est obligatoire',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères',
            'password.mixed' => 'Le mot de passe doit contenir au moins une majuscule et une minuscule',
            'password.numbers' => 'Le mot de passe doit contenir au moins un chiffre',
            'password.symbols' => 'Le mot de passe doit contenir au moins un symbole',
            'password_confirmation.required' => 'La confirmation du mot de passe est obligatoire',
            'password_confirmation.same' => 'La confirmation du mot de passe doit être identique au mot de passe',
            'company_share.numeric' => 'La part de l\'entreprise doit être un nombre',
            'company_share.min' => 'La part de l\'entreprise doit être supérieure ou égale à 0',
            'company_share.max' => 'La part de l\'entreprise doit être inférieure ou égale à 100',
        ];
    }
}
