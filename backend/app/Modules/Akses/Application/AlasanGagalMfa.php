<?php

namespace App\Modules\Akses\Application;

enum AlasanGagalMfa: string
{
    case KodeSalah = 'kode_mfa_salah';
    case AkunTerkunci = 'akun_terkunci';
}
