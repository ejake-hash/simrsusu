<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Core\Http\ApiError;
use App\Core\Http\Paginasi;
use App\Modules\MasterData\Application\ButirRuangTt;
use App\Modules\MasterData\Http\Resources\UsulanPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /api/v1/data-induk/{jenis}/{id}/riwayat-versi (kontrak v0.9.0, tiket 1.8c). Ruang/kamar/TT: izin master_data.organisasi.lihat. */
final class RiwayatVersiController
{
    use IzinOrganisasi;

    public function __invoke(Request $request, ButirRuangTt $butir, UsulanPresenter $presenter, string $jenis, string $objekId): JsonResponse
    {
        $this->izin('lihat');
        if ($butir->objek($jenis, $objekId) === null) {
            throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');
        }
        $query = DB::table('inti.data_induk_versi')->where('objek_jenis', $jenis)->where('objek_id', $objekId);

        return Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn ($baris) => $presenter->versi($baris));
    }
}
