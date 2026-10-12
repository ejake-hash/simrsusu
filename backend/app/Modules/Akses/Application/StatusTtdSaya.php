<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Models\Pengguna;
use Illuminate\Http\Request;

/** Saya.ttd (kontrak v0.8.0); diisi modul Ttd (tiket 1.6). */
interface StatusTtdSaya
{
    /** @return array<string, mixed> */
    public function untuk(Request $request, Pengguna $pengguna): array;
}
