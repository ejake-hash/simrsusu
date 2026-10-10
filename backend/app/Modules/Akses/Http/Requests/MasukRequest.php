<?php

namespace App\Modules\Akses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MasukRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Panjang minimal sengaja tidak dicek di sini; aturan sandi berlaku saat sandi ditetapkan. */
    public function rules(): array
    {
        return [
            'nama_pengguna' => ['required', 'string', 'max:150'],
            'sandi' => ['required', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama_pengguna.required' => 'Nama pengguna wajib diisi.',
            'sandi.required' => 'Sandi wajib diisi.',
            '*.string' => 'Isian tidak valid.',
            '*.max' => 'Isian terlalu panjang.',
        ];
    }
}
