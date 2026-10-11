<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;

/** Jumlah tugas terbuka milik akun untuk lencana menu Beranda (MenuSaya.jumlah_tugas); diisi modul Notifikasi (1.7). */
interface JumlahTugasSaya
{
    public function untuk(Pengguna $pengguna): ?int;
}
