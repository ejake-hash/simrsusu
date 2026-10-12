<?php

namespace App\Modules\Integrasi\Application;

use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** `integrasi.id_eksternal` (PRD 11.2): id data lokal di sistem luar per lingkungan. */
final class PetaIdEksternal
{
    /** Bentuk id resource FHIR (FHIR R4 `id`); id lain tidak disimpan dan tidak dimasukkan ke path URL. */
    public const POLA_ID_FHIR = '/^[A-Za-z0-9\-.]{1,64}$/';

    public function idLuar(SistemIntegrasi $sistem, string $lingkungan, string $jenisLokal, string $idLokal): ?string
    {
        return DB::table('integrasi.id_eksternal')->where('sistem', $sistem->value)->where('lingkungan', $lingkungan)
            ->where('jenis_lokal', $jenisLokal)->where('id_lokal', $idLokal)->value('id_luar');
    }

    /** @param  string  $sidik  ringkasan data lokal yang dibaca untuk kiriman ini; perubahan sesudahnya membuat sidik berbeda */
    public function simpan(SistemIntegrasi $sistem, string $lingkungan, string $jenisLokal, string $idLokal, string $idLuar, string $sidik): void
    {
        DB::table('integrasi.id_eksternal')->upsert([
            'id' => (string) Str::uuid7(), 'sistem' => $sistem->value, 'lingkungan' => $lingkungan, 'jenis_lokal' => $jenisLokal,
            'id_lokal' => $idLokal, 'id_luar' => $idLuar, 'sidik_sumber' => $sidik, 'disinkron_pada' => now(), 'diubah_pada' => now(),
        ], ['sistem', 'lingkungan', 'jenis_lokal', 'id_lokal'], ['id_luar', 'sidik_sumber', 'disinkron_pada', 'diubah_pada']);
    }

    /**
     * Sidik data yang terakhir dikirim per data lokal satu jenis.
     *
     * @return array<string, string> id_lokal => sidik_sumber
     */
    public function sidik(SistemIntegrasi $sistem, string $lingkungan, string $jenisLokal): array
    {
        return DB::table('integrasi.id_eksternal')->where('sistem', $sistem->value)->where('lingkungan', $lingkungan)
            ->where('jenis_lokal', $jenisLokal)->pluck('sidik_sumber', 'id_lokal')->all();
    }
}
