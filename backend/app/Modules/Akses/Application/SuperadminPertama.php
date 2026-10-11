<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Modules\Akses\Database\MenuBawaan;
use App\Modules\Akses\Domain\Totp;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\Peran;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Superadmin pertama instalasi baru (FR-54-08 langkah 1, PRD 9.2.4): dua akun bernama orang dengan
 * peran superadmin (wajib MFA), dibuat dari konsol server karena pendaftaran MFA lewat web hanya
 * dari jaringan RS. Superadmin berikutnya diberikan dari aplikasi oleh superadmin yang ada.
 */
final class SuperadminPertama
{
    public const BATAS = 2;

    public const AKSI_AUDIT = 'akses.superadmin_dibuat';

    public function __construct(
        private readonly Hasher $hasher,
        private readonly VerifikasiMfa $mfa,
        private readonly CatatAktivitas $audit,
    ) {}

    public function jumlahAktif(): int
    {
        return Pengguna::query()
            ->where('status', 'aktif')
            ->whereIn('id', PenggunaPeran::query()->berlaku()
                ->whereHas('peran', fn ($peran) => $peran->where('kode', MenuBawaan::PERAN_SUPERADMIN))
                ->select('pengguna_id'))
            ->count();
    }

    /**
     * @return array{pengguna: Pengguna, rahasia: string, kode_cadangan: list<string>}
     *
     * @throws RuntimeException bila sudah ada BATAS superadmin aktif
     */
    public function buat(string $namaPengguna, string $nama, ?string $nip, ?string $email, string $sandi): array
    {
        $sandiHash = $this->hasher->make($sandi);

        return DB::transaction(function () use ($namaPengguna, $nama, $nip, $email, $sandiHash) {
            // Dua perintah bersamaan tidak bisa sama-sama lolos hitungan di bawah.
            DB::select("SELECT pg_advisory_xact_lock(hashtext('akses.superadmin_pertama'))");
            if ($this->jumlahAktif() >= self::BATAS) {
                throw new RuntimeException('Sudah ada '.self::BATAS.' superadmin aktif.');
            }

            $pegawai = Pegawai::query()->create(['nip' => $nip, 'nama' => $nama]);
            $pengguna = new Pengguna(['pegawai_id' => $pegawai->id, 'nama_pengguna' => $namaPengguna, 'email' => $email]);
            $pengguna->forceFill(['sandi_hash' => $sandiHash, 'status' => 'aktif'])->save();
            PenggunaPeran::query()->create([
                'pengguna_id' => $pengguna->id,
                'peran_id' => Peran::query()->where('kode', MenuBawaan::PERAN_SUPERADMIN)->value('id'),
                'dasar_pemberian' => 'Konsol server: akses:superadmin-pertama (FR-54-08)',
            ]);

            $rahasia = Totp::rahasiaBaru();
            $kodeCadangan = $this->mfa->pasang($pengguna, $rahasia);
            $this->audit->catat(self::AKSI_AUDIT, null, 'akses.pengguna', $pengguna->id, [
                'nama_pengguna' => $pengguna->nama_pengguna,
                'sumber' => 'konsol',
                'peran' => MenuBawaan::PERAN_SUPERADMIN,
            ]);

            return ['pengguna' => $pengguna, 'rahasia' => $rahasia, 'kode_cadangan' => $kodeCadangan];
        });
    }
}
