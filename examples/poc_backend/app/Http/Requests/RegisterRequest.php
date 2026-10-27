<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * S3 A03: name, email, Taiwan mobile and password are all required at registration.
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $phone = $this->input('phone');

        $this->merge([
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            'phone' => is_string($phone) && trim($phone) !== '' ? trim($phone) : null,
            'phone_e164' => is_string($phone) ? Phone::normalize($phone) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'phone_e164' => ['nullable', 'unique:users,phone_e164'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'family_name' => ['nullable', 'string', 'max:255'],
            'family_relationship' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('phone') !== null && $this->input('phone_e164') === null) {
                    $validator->errors()->add('phone', '請輸入台灣手機號碼，例如 0912345678。');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => '請輸入姓名。',
            'email.required' => '請輸入 Email。',
            'phone.required' => '請輸入手機號碼。',
            'email.unique' => '此 Email 無法使用，請改用其他 Email 或使用忘記密碼。',
            'phone_e164.unique' => '此手機號碼無法使用，請改用其他號碼或使用忘記密碼。',
            'password.min' => '密碼至少 8 個字元。',
            'password.confirmed' => '兩次輸入的密碼不一致。',
        ];
    }
}
