<?php

namespace App\Modules\Akses\Application;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Modules\Akses\Models\LabelSensitivitas;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaLabel;
use App\Modules\Akses\Models\Peran;
use App\Modules\Akses\Models\PersetujuanAkses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lapis 4 (tiket 1.5; PRD 9.2.2–9.2.4; kontrak v0.14.0):
 * - penyetuju label (pejabat PDP) ditetapkan RS, selalu lewat persetujuan superadmin lain (K13), seperti PenyetujuIzin;
 * - izin label per akun selalu lewat persetujuan pejabat PDP (jenis `izin_label`), berbatas waktu, dicabut langsung.
 */
final class KelolaLabel
{
    public function __construct(
        private readonly PenjagaPenyunting $penjaga,
        private readonly AlurPersetujuan $alur,
        private readonly CatatAktivitas $audit,
    ) {}

    public function aturPenyetuju(Pengguna $penyunting, LabelSensitivitas $label, string $peranId, string $dasar): PersetujuanAkses
    {
        $peran = Peran::query()->whereKey($peranId)->where('status', 'aktif')->first();
        if ($peran === null) {
            throw new ApiError(422, 'peran_tidak_valid', 'Pilih peran yang ada dan aktif.', [['kolom' => 'peran_id', 'pesan' => 'Peran tidak ada atau nonaktif.']]);
        }
        $this->penjaga->pastikanBukanPeranSendiri($penyunting, $peran->id);
        if ($label->peran_penyetuju_id === $peran->id) {
            throw new ApiError(422, 'peran_tidak_valid', 'Peran ini sudah menjadi penyetuju label tersebut.', [['kolom' => 'peran_id', 'pesan' => 'Pilih peran lain.']]);
        }

        return DB::transaction(function () use ($penyunting, $label, $peran, $dasar) {
            LabelSensitivitas::query()->whereKey($label->id)->lockForUpdate()->first();
            if (PersetujuanAkses::query()->menunggu()->where('objek_jenis', 'penyetuju_label')->where('objek->label_id', $label->id)->exists()) {
                throw new ApiError(409, 'persetujuan_ganda', 'Penggantian penyetuju label ini masih menunggu persetujuan.');
            }

            return $this->alur->ajukan($penyunting, 'izin_sensitif', 'penyetuju_label', [
                'label_id' => $label->id, 'label_kode' => $label->kode, 'label_nama' => $label->nama,
                'peran_id' => $peran->id, 'peran_penyetuju_sebelum_id' => $label->peran_penyetuju_id,
            ], [$this->alur->peranSuperadmin()], ['peran_id' => $peran->id], $dasar, null)[0];
        });
    }

    /** @param  array{label_kode: string, berlaku_sampai: string, dasar_pemberian: string, alasan: string}  $isi */
    public function ajukan(Pengguna $penyunting, Pengguna $akun, array $isi): PersetujuanAkses
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);
        $label = LabelSensitivitas::query()->where('kode', $isi['label_kode'])->where('aktif', true)->first()
            ?? throw new ApiError(404, 'tidak_ditemukan', 'Label tidak ditemukan.');
        if ($label->peran_penyetuju_id === null) {
            throw new ApiError(422, 'penyetuju_label_belum_ditetapkan', 'Label ini belum punya penyetuju (pejabat PDP). Tetapkan dulu di pengaturan label.', [
                ['kolom' => 'label_kode', 'pesan' => 'Penyetuju belum ditetapkan'],
            ]);
        }
        $sampai = Carbon::parse($isi['berlaku_sampai']);
        $maks = (int) config('akses.label_maks_hari');
        if (! $sampai->isFuture() || $sampai->greaterThan(now()->addDays($maks))) {
            throw new ApiError(422, 'tanggal_tidak_valid', 'Masa berlaku izin label tidak valid.', [
                ['kolom' => 'berlaku_sampai', 'pesan' => "Isi tanggal di masa depan, paling lama $maks hari dari sekarang."],
            ]);
        }

        return DB::transaction(function () use ($penyunting, $akun, $isi, $label, $sampai) {
            Pengguna::query()->whereKey($akun->id)->lockForUpdate()->first();
            $milikAkun = PenggunaLabel::query()->where('pengguna_id', $akun->id)->where('label_sensitivitas_id', $label->id);
            if ((clone $milikAkun)->menunggu()->exists()) {
                throw new ApiError(409, 'persetujuan_ganda', 'Pengajuan label yang sama untuk akun ini masih menunggu persetujuan.');
            }
            if ((clone $milikAkun)->berlaku()->exists()) {
                throw new ApiError(409, 'label_masih_berlaku', 'Akun ini masih punya izin label tersebut. Cabut dulu bila ingin mengganti masa berlakunya.');
            }

            $p = $this->alur->ajukan($penyunting, 'izin_label', 'izin_label', [
                'pengguna_id' => $akun->id, 'label_id' => $label->id, 'label_kode' => $label->kode, 'label_nama' => $label->nama,
                'berlaku_sampai' => $sampai->toIso8601String(), 'dasar_pemberian' => $isi['dasar_pemberian'], 'alasan' => $isi['alasan'],
            ], [(string) $label->peran_penyetuju_id], ['sasaran_pengguna_id' => $akun->id], $isi['dasar_pemberian'], $isi['alasan'])[0];

            (new PenggunaLabel)->forceFill([
                'pengguna_id' => $akun->id, 'label_sensitivitas_id' => $label->id, 'persetujuan_akses_id' => $p->id, 'status' => 'menunggu',
                'berlaku_sampai' => $sampai, 'dasar_pemberian' => $isi['dasar_pemberian'], 'alasan' => $isi['alasan'],
                'diberikan_oleh' => $penyunting->id, 'dibuat_oleh' => $penyunting->id, 'diubah_oleh' => $penyunting->id,
            ])->save();

            return $p;
        });
    }

    /** Mencabut izin yang sedang berlaku; langsung mengurangi akses (cache akun dihapus lewat model). */
    public function batalkan(Pengguna $penyunting, Pengguna $akun, PenggunaLabel $izin, string $alasan): PenggunaLabel
    {
        $this->penjaga->pastikanBolehMengubahAkun($penyunting, $akun);

        return DB::transaction(function () use ($penyunting, $izin, $alasan) {
            $izin = PenggunaLabel::query()->whereKey($izin->id)->lockForUpdate()->firstOrFail();
            if ($izin->status !== 'aktif' || ! $izin->berlaku_sampai->isFuture()) {
                throw new ApiError(409, 'sudah_tidak_berlaku', 'Izin label ini sudah tidak berlaku. Pengajuan yang masih menunggu ditarik dari daftar persetujuan.');
            }
            $izin->forceFill([
                'status' => 'batal', 'alasan_batal' => $alasan, 'dibatalkan_oleh' => $penyunting->id,
                'dibatalkan_pada' => now(), 'diubah_oleh' => $penyunting->id,
            ])->save();
            $this->audit->catat('akses.label_dicabut', $penyunting->id, 'akses.pengguna', $izin->pengguna_id, [
                'pengguna_label_id' => $izin->id, 'label' => $izin->label->kode, 'alasan_batal' => $alasan,
            ]);

            return $izin;
        });
    }
}
