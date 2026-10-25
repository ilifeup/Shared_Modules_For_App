<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'display_name' => ['required_without_all:name,nickname,show_in_like_list', 'string', 'max:80'],
            // S8 M01: unified public nickname shown with likes and comments (never the real name).
            'nickname' => ['sometimes', 'required', 'string', 'min:2', 'max:20', 'regex:/^[\p{L}\p{N}_\- ]+$/u',
                Rule::unique('users', 'nickname')->ignore($this->user()?->id)],
            'show_in_like_list' => ['sometimes', 'boolean'],
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
            'nickname.required' => '請輸入公開暱稱。',
            'nickname.min' => '公開暱稱需為 2–20 字。',
            'nickname.max' => '公開暱稱需為 2–20 字。',
            'nickname.regex' => '公開暱稱只能使用文字、數字、空白、底線或連字號。',
            'nickname.unique' => '這個暱稱已有人使用，請換一個。',
        ];
    }
}
