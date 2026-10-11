<?php

namespace App\Modules\MasterData\Database\Seeders;

use App\Modules\MasterData\Models\Ksm;
use App\Modules\MasterData\Models\UnitOrganisasi;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Unit organisasi dan KSM contoh untuk lokal/staging (FR-54-10), agar pohon dan daftar tidak kosong. Profil RS
 * sengaja tidak diisi: alur instalasi kosong tetap teruji. Aman diulang (dicocokkan per kode). Ditolak di production.
 */
final class OrganisasiSintetisSeeder extends Seeder
{
    /** kode => [nama, jenis, kode induk] */
    private const UNIT = [
        'IRJ' => ['Instalasi Rawat Jalan (contoh)', 'instalasi', null],
        'POLI-PD' => ['Poli Penyakit Dalam (contoh)', 'poli', 'IRJ'],
        'POLI-ANAK' => ['Poli Anak (contoh)', 'poli', 'IRJ'],
        'IRNA' => ['Instalasi Rawat Inap (contoh)', 'instalasi', null],
        'RUANG-MELATI' => ['Ruang Melati (contoh)', 'ruang', 'IRNA'],
        'RUANG-MAWAR' => ['Ruang Mawar (contoh)', 'ruang', 'IRNA'],
        'IGD' => ['Instalasi Gawat Darurat (contoh)', 'instalasi', null],
        'FARMASI' => ['Instalasi Farmasi (contoh)', 'instalasi', null],
        'DEPO-RJ' => ['Depo Farmasi Rawat Jalan (contoh)', 'depo', 'FARMASI'],
    ];

    private const KSM = [
        'KSM-IPD' => 'KSM Ilmu Penyakit Dalam (contoh)',
        'KSM-ANAK' => 'KSM Ilmu Kesehatan Anak (contoh)',
        'KSM-BEDAH' => 'KSM Bedah (contoh)',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('OrganisasiSintetisSeeder ditolak berjalan di production.');
        }

        $id = [];
        foreach (self::UNIT as $kode => [$nama, $jenis, $induk]) {
            $id[$kode] = UnitOrganisasi::query()->updateOrCreate(['kode' => $kode], [
                'nama' => $nama, 'jenis' => $jenis, 'induk_id' => $induk === null ? null : $id[$induk],
                'status' => 'aktif', 'berlaku_dari' => '2026-01-01',
            ])->id;
        }
        foreach (self::KSM as $kode => $nama) {
            Ksm::query()->updateOrCreate(['kode' => $kode], ['nama' => $nama, 'status' => 'aktif']);
        }
    }
}
