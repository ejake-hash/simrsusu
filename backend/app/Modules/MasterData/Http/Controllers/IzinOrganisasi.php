<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Modules\Akses\Domain\Cakupan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Izin data induk lokasi/ruang/TT (tiket 1.8b): sub-menu master_data.organisasi dengan cakupan `rs` (PRD 9.1). */
trait IzinOrganisasi
{
    private function izin(string $aksi): void
    {
        abort_unless(Gate::allows("master_data.organisasi.$aksi", Cakupan::rs()), 403);
    }

    /** @param  array<string, mixed>  $isi */
    private function minimalSatuPerubahan(array $isi): void
    {
        if (count($isi) < 2) {
            throw ValidationException::withMessages(['dasar_perubahan' => 'Isi minimal satu perubahan.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function respons(array $data, int $versi, int $status = 200, array $tambahan = []): JsonResponse
    {
        return (new JsonResponse(['data' => $data, ...$tambahan], $status))->setEtag((string) $versi)->setPrivate();
    }

    private static function benar(Request $request, string $kunci): bool
    {
        return in_array($request->query($kunci), ['true', '1'], true);
    }
}
