<?php

namespace App\Modules\Akses\Application;

enum AlasanGagalMasuk: string
{
    case KredensialSalah = 'kredensial_salah';
    case AkunTerkunci = 'akun_terkunci';
    case AkunNonaktif = 'akun_nonaktif';
}
