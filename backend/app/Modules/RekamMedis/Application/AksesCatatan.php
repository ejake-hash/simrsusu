<?php

namespace App\Modules\RekamMedis\Application;

use App\Modules\Akses\Application\PemeriksaLabel;
use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\RekamMedis\Models\CatatanKlinis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Aturan baca catatan klinis (tiket 2.13/2.14, kontrak v0.24.0), satu sumber untuk controller dan verifikasi TTE:
 * izin per jenis catatan dengan cakupan unit (CPPT: unit salah satu kunjungan episode, lintas pos 7.26.1), draf hanya
 * penulisnya, dan lapis 4 (label) lewat PemeriksaLabel kecuali bagi penulisnya.
 */
final class AksesCatatan
{
    public const LIHAT_CPPT = ['rawat_jalan.cppt.lihat', 'rawat_jalan.pemeriksaan.lihat'];

    public const LIHAT_ASESMEN = ['rawat_jalan.asesmen_perawat.lihat', 'rawat_jalan.pemeriksaan.lihat'];

    public function __construct(private readonly PemeriksaLabel $label) {}

    /**
     * Akun memegang salah satu izin di cakupan unit salah satu kunjungan yang cocok dengan filter.
     *
     * @param  list<string>  $izin
     * @param  array{episode_id?: string, pasien_id?: list<string>, tanpa_batal?: bool}  $filter
     */
    public function bolehBacaKunjungan(Pengguna $pengguna, array $izin, array $filter): bool
    {
        $unit = DB::table('kunjungan.kunjungan AS k')->join('inti.poliklinik AS p', 'p.id', '=', 'k.poli_id')
            ->when($filter['episode_id'] ?? null, fn ($q, $id) => $q->where('k.episode_id', $id))
            ->when($filter['pasien_id'] ?? null, fn ($q, $id) => $q->whereIn('k.pasien_id', $id))
            ->when($filter['tanpa_batal'] ?? false, fn ($q) => $q->where('k.status', '<>', 'batal'))
            ->distinct()->limit(200)->pluck('p.unit_organisasi_id');
        $gate = Gate::forUser($pengguna);

        return $unit->contains(fn ($u) => array_any($izin, fn ($i) => $gate->allows($i, new Cakupan('unit', (string) $u))));
    }

    /** Catatan boleh dibaca utuh (bukan Terbatas). Draf orang lain dianggap tidak ada; cek izin/cakupan di pemanggil. */
    public function terbuka(Pengguna $pengguna, CatatanKlinis $c): bool
    {
        return $c->penulis_id === $pengguna->id || $this->label->boleh($pengguna, $c->label, $c->pasien_id);
    }

    /** Terlihat sama sekali (izin + cakupan + bukan draf orang lain); dipakai GET satu catatan dan verifikasi TTE. */
    public function terlihat(Pengguna $pengguna, CatatanKlinis $c): bool
    {
        if ($c->status === 'draf' && $c->penulis_id !== $pengguna->id && $c->jenis === 'soap') {
            return false;
        }
        if ($c->jenis === 'asesmen') {
            $gate = Gate::forUser($pengguna);

            return array_any(self::LIHAT_ASESMEN, fn ($i) => $gate->allows($i, new Cakupan('unit', $c->unit_id)));
        }

        return $this->bolehBacaKunjungan($pengguna, self::LIHAT_CPPT, ['episode_id' => $c->episode_id]);
    }
}
