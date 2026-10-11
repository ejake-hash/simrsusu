<?php

namespace App\Modules\MasterData\Application;

use App\Core\Http\ApiError;
use App\Core\Http\IfMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Pola bersama data induk 1.8b: versi = kolom revisi (compare-and-set di awal transaksi, 412 bila berubah) dan
 * pelanggaran kunci unik → 409 kode_sudah_dipakai.
 */
final class SuntinganBerversi
{
    /**
     * Naikkan revisi bila masih sama dengan If-Match; dipanggil di dalam transaksi sebelum perubahan lain.
     *
     * @param  class-string<Model>  $model
     */
    public static function naikkan(string $model, string $id, string $versi, string $oleh, string $label): void
    {
        $revisi = IfMatch::revisi($versi);
        $naik = $revisi !== null && $model::query()->whereKey($id)->where('revisi', $revisi)
            ->update(['revisi' => DB::raw('revisi + 1'), 'diubah_oleh' => $oleh]) === 1;
        if (! $naik) {
            throw new ApiError(412, 'versi_berubah', "$label ini diubah orang lain sejak Anda membukanya. Muat ulang untuk melihat perubahan terbaru.");
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $kerja
     * @return T
     */
    public static function tangkapKodeGanda(callable $kerja, string $kolom = 'kode'): mixed
    {
        try {
            return $kerja();
        } catch (QueryException $e) {
            throw $e->getCode() === '23505'
                ? new ApiError(409, 'kode_sudah_dipakai', 'Kode sudah dipakai.', [['kolom' => $kolom, 'pesan' => 'Kode sudah dipakai.']])
                : $e;
        }
    }

    public static function hariIni(): string
    {
        return now(config('instalasi.zona_waktu'))->toDateString();
    }
}
