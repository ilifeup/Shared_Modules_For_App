<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only the authenticated member's own profile is ever updated (no id in the route).
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['required_without:name', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => '請輸入姓名。',
            'display_name.required_without' => '請輸入顯示名稱。',
            'display_name.max' => '顯示名稱最多 80 字。',
        ];
    }
}
