<?php

namespace App\Modules\Akses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Dipakai ketiga endpoint MFA; `metode` hanya wajib di POST /autentikasi/mfa. */
final class MfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
            'metode' => [$this->routeIs('autentikasi.mfa') ? 'required' : 'prohibited', 'string', 'in:totp,kode_cadangan'],
            'kode' => [$this->routeIs('autentikasi.mfa.daftar') ? 'prohibited' : 'required', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'challenge_id.required' => 'Sesi verifikasi tidak ditemukan. Silakan masuk lagi.',
            'kode.required' => 'Kode wajib diisi.',
            'metode.in' => 'Metode verifikasi tidak dikenal.',
            '*.string' => 'Isian tidak valid.',
            '*.max' => 'Isian terlalu panjang.',
            '*.prohibited' => 'Isian tidak dikenal.',
        ];
    }
}
