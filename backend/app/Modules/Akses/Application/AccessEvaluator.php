<?php

namespace App\Modules\Akses\Application;

use App\Modules\Akses\Domain\Cakupan;
use App\Modules\Akses\Domain\HakAkses;
use App\Modules\Akses\Domain\RelasiPerawatan;
use App\Modules\Akses\Domain\TindakanKlinis;
use App\Modules\Akses\Models\Izin;
use App\Modules\Akses\Models\Menu;
use App\Modules\Akses\Models\PemberianAkses;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Akses\Models\PenggunaIzinKhusus;
use App\Modules\Akses\Models\PenggunaLabel;
use App\Modules\Akses\Models\PenggunaPeran;
use App\Modules\Akses\Models\PeranIzin;
use App\Modules\Akses\Models\PeranMenu;
use App\Modules\Sdm\Application\KewenanganPraktik;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya penentu hak akses (PRD 9.2.5, D.4): dipakai Gate (lihat ModuleServiceProvider),
 * menu sidebar, dan pembentuk respons. Tiket 1.2a mengerjakan lapis 1 (menu/bagian), 2 (aksi),
 * dan 3 (cakupan data; cakupan `unit` memuat semua unit turunannya sejak tiket 1.8a); tiket 1.5 lapis 4–5.
 *
 *   akun aktif dan tidak terkunci
 *   dan tidak ada pencabutan per akun atas sub-menu, bagian, atau aksi itu   ← cabut menang
 *   dan ada penugasan peran atau pemberian per akun yang berlaku sekarang yang memberinya,
 *       dengan cakupan yang memuat data itu
 *   dan (lapis 4, per objek) bila data berlabel: izin label berlaku dan merawat pasiennya   ← bolehLabel()
 *   dan (lapis 5) SIP/SPK dan RKK berlaku, MFA sesi masih baru untuk izin butuh_mfa       ← periksa()
 *
 * boleh() = lapis 1–3, dipakai membentuk layar (tombol tetap tampil walau MFA perlu diulang). Gate memakai
 * periksa() = lapis 1–3 dan 5, dengan kode penolakan lapis 5 yang bisa ditindaklanjuti klien.
 */
final class AccessEvaluator
{
    /** Batas atas lama cache bila tidak ada masa berlaku yang lebih dekat. */
    private const MAKS_CACHE_DETIK = 900;

    public function __construct(
        private readonly CacheHakAkses $cache,
        private readonly DataUnit $dataUnit,
        private readonly KewenanganPraktik $kewenangan,
        private readonly RelasiPerawatan $relasi,
    ) {}

    public function hak(Pengguna $pengguna): HakAkses
    {
        if (! $pengguna->aktif() || $pengguna->terkunci()) {
            return new HakAkses;
        }

        return $this->cache->ambil($pengguna->id, fn () => $this->hitung($pengguna));
    }

    /** Lapis 1: sub-menu, bagian, atau menu tanpa sub-menu. */
    public function bolehMenu(Pengguna $pengguna, string $kode): bool
    {
        return $this->hak($pengguna)->bolehMenu($kode);
    }

    /** Lapis 1–3. Tanpa $data: cukup punya izin itu di cakupan mana pun. */
    public function boleh(Pengguna $pengguna, string $izin, ?Cakupan $data = null): bool
    {
        $cakupan = $this->hak($pengguna)->cakupan($izin);
        if ($data === null) {
            return $cakupan !== [];
        }

        // Data milik sebuah unit: cakupan unit mana pun di jalur leluhurnya memuatnya.
        $leluhur = $data->jenis === 'unit' && $data->id !== null ? $this->dataUnit->leluhur($data->id) : [];

        return array_any($cakupan, fn (Cakupan $c) => $c->memuat($data)
            || ($c->jenis === 'unit' && in_array($c->id, $leluhur, true)));
    }

    /**
     * Lapis 1–3 lalu lapis 5 (PRD 9.2.2: lapis 5 mengalahkan semua pemberian). Penolakan lapis 5 membawa kode:
     * `izin_praktik_tidak_berlaku`, `kewenangan_klinis_tidak_mencakup` (bila $tindakan diberikan), `mfa_belum_terdaftar`,
     * `mfa_ulang_diperlukan`. Syarat yang tidak bisa diperbaiki pengguna sendiri diperiksa lebih dulu.
     */
    public function periksa(Pengguna $pengguna, string $izin, ?Cakupan $data = null, ?TindakanKlinis $tindakan = null): Response
    {
        if (! $this->boleh($pengguna, $izin, $data)) {
            return Response::deny();
        }
        $syarat = $this->hak($pengguna)->syarat($izin);
        if ($syarat['klinis']) {
            if (! $this->kewenangan->izinPraktikBerlaku($pengguna->pegawai_id)) {
                return Response::deny('Izin praktik (SIP/SPK) Anda tidak berlaku hari ini. Hubungi bagian SDM.', 'izin_praktik_tidak_berlaku');
            }
            if ($tindakan !== null && ! $this->kewenangan->rkkMemuat($pengguna->pegawai_id, $tindakan->kode)) {
                return Response::deny('Rincian kewenangan klinis Anda tidak memuat tindakan ini.', 'kewenangan_klinis_tidak_mencakup');
            }
        }
        if ($syarat['mfa']) {
            if (! $pengguna->mfaAktif()) {
                return Response::deny('Aksi ini butuh MFA. Daftarkan MFA dari jaringan RS atau hubungi admin.', 'mfa_belum_terdaftar');
            }
            if (! MfaUlang::masihBerlaku(request())) {
                return Response::deny('Masukkan kode MFA lagi untuk melanjutkan.', 'mfa_ulang_diperlukan');
            }
        }

        return Response::allow();
    }

    /**
     * Lapis 4 untuk satu objek berlabel (PRD 9.2.2): izin label yang disetujui dan masih berlaku, DAN merawat pasiennya
     * bila label itu butuh relasi perawatan. Label tidak dikenal atau nonaktif = tertutup (tolak kecuali diberikan).
     */
    public function bolehLabel(Pengguna $pengguna, string $label, ?string $pasienId): bool
    {
        if (! $this->punyaIzinLabel($pengguna, $label)) {
            return false;
        }
        $butuhRelasi = DB::table('akses.label_sensitivitas')->where('kode', $label)->where('aktif', true)->value('butuh_relasi_perawatan');
        if ($butuhRelasi === null) {
            return false;
        }

        return ! $butuhRelasi || $this->relasi->merawat($pengguna, $pasienId);
    }

    /** Separuh pertama lapis 4: izin label akun ini disetujui pejabat PDP dan belum berakhir atau dicabut. */
    public function punyaIzinLabel(Pengguna $pengguna, string $label): bool
    {
        return $this->hak($pengguna)->punyaLabel($label, now()->getTimestamp());
    }

    /**
     * Unit akar cakupan sebuah izin untuk menyaring query daftar: null = seluruh RS (cakupan `rs`), list kosong
     * = tidak ada, selain itu id unit yang subpohonnya boleh (perluas dengan pohon unit).
     *
     * @return list<string>|null
     */
    public function unitTerjangkau(Pengguna $pengguna, string $izin): ?array
    {
        $unit = [];
        foreach ($this->cakupan($pengguna, $izin) as $c) {
            if ($c->jenis === 'rs') {
                return null;
            }
            if ($c->jenis === 'unit' && $c->id !== null) {
                $unit[] = $c->id;
            }
        }

        return array_values(array_unique($unit));
    }

    /**
     * Cakupan yang dimiliki untuk sebuah izin, untuk memaksakan filter di query daftar.
     *
     * @return list<Cakupan>
     */
    public function cakupan(Pengguna $pengguna, string $izin): array
    {
        return $this->hak($pengguna)->cakupan($izin);
    }

    /** @return array{0: HakAkses, 1: int} */
    private function hitung(Pengguna $pengguna): array
    {
        $sekarang = now();
        $penugasan = PenggunaPeran::query()->where('pengguna_id', $pengguna->id)->where('status', 'aktif')
            ->whereHas('peran', fn ($q) => $q->where('status', 'aktif'))->get();
        $khusus = PenggunaIzinKhusus::query()->where('pengguna_id', $pengguna->id)->where('status', 'aktif')->get();

        // Cache berakhir paling lambat saat penugasan/pemberian berikutnya mulai atau berhenti berlaku.
        $batas = collect([...$penugasan, ...$khusus])
            ->flatMap(fn (PemberianAkses $p) => [$p->berlaku_dari, $p->berlaku_sampai])
            ->filter(fn ($waktu) => $waktu !== null && $waktu->greaterThan($sekarang))
            ->min();
        $detik = $batas === null ? self::MAKS_CACHE_DETIK
            : max(1, min(self::MAKS_CACHE_DETIK, (int) ceil($sekarang->diffInSeconds($batas))));

        $berlaku = fn (PemberianAkses $p) => $p->berlaku_dari->lessThanOrEqualTo($sekarang)
            && ($p->berlaku_sampai === null || $p->berlaku_sampai->greaterThan($sekarang));
        $penugasan = $penugasan->filter($berlaku);
        $khusus = $khusus->filter($berlaku);
        $tambah = $khusus->where('efek', 'tambah');
        $cabut = $khusus->where('efek', 'cabut');

        $semuaMenu = Menu::query()->get(['id', 'induk_id', 'tingkat', 'kode', 'ikut_induk', 'aktif', 'selalu_tampil'])->keyBy('id');
        $anak = $semuaMenu->toBase()->groupBy('induk_id', preserveKeys: true);

        // Lapis 1.
        $diberi = PeranMenu::query()->whereIn('peran_id', $penugasan->pluck('peran_id'))->pluck('menu_id')
            ->merge($tambah->pluck('menu_id')->filter())
            ->merge($semuaMenu->where('selalu_tampil', true)->keys());
        $diberi = $diberi->merge($diberi->flatMap(fn ($id) => ($anak[$id] ?? collect())
            ->where('tingkat', 'bagian')->where('ikut_induk', true)->keys()));
        $dicabut = $cabut->pluck('menu_id')->filter()->flatMap(fn ($id) => [$id, ...$this->keturunan($id, $anak)]);
        $menuBoleh = $diberi->unique()->diff($dicabut)
            ->filter(fn ($id) => $this->aktifSampaiAkar($id, $semuaMenu))
            ->flip();

        // Lapis 2 dan 3: setiap izin membawa cakupan penugasan/pemberian yang memberikannya.
        $cakupanPeran = $penugasan->groupBy('peran_id');
        $cakupanIzin = [];
        foreach (PeranIzin::query()->whereIn('peran_id', $cakupanPeran->keys())->get(['peran_id', 'izin_id']) as $pi) {
            foreach ($cakupanPeran[$pi->peran_id] as $p) {
                $cakupanIzin[$pi->izin_id][] = [$p->cakupan_jenis, $p->cakupan_id];
            }
        }
        foreach ($tambah->whereNotNull('izin_id') as $p) {
            $cakupanIzin[$p->izin_id][] = [$p->cakupan_jenis, $p->cakupan_id];
        }
        $izinDicabut = $cabut->pluck('izin_id')->filter()->flip();

        $izin = [];
        foreach (Izin::query()->whereIn('id', array_keys($cakupanIzin))->get(['id', 'kode', 'menu_id']) as $i) {
            if (! isset($izinDicabut[$i->id]) && isset($menuBoleh[$i->menu_id])) {
                $izin[$i->kode] = array_values(array_unique($cakupanIzin[$i->id], SORT_REGULAR));
            }
        }
        ksort($izin);

        $menu = array_fill_keys($semuaMenu->only($menuBoleh->keys()->all())->pluck('kode')->all(), true);

        // Lapis 4: izin label yang sudah disetujui (masa berlaku dibandingkan saat dibaca; berubah → cache dihapus).
        $label = PenggunaLabel::query()->berlaku()->where('pengguna_id', $pengguna->id)
            ->join('akses.label_sensitivitas as l', 'l.id', '=', 'akses.pengguna_label.label_sensitivitas_id')->where('l.aktif', true)
            ->groupBy('l.kode')->selectRaw('l.kode, max(akses.pengguna_label.berlaku_sampai) AS sampai')->toBase()->pluck('sampai', 'kode')
            ->map(fn ($sampai) => Carbon::parse((string) $sampai)->getTimestamp())->all();

        // Lapis 5: syarat per izin yang dimiliki; dinilai saat diminta (SIP, MFA sesi), bukan di cache.
        $syarat = Izin::query()->whereIn('kode', array_keys($izin))
            ->where(fn ($q) => $q->where('butuh_mfa', true)->orWhere('butuh_kewenangan_klinis', true))
            ->get(['kode', 'butuh_mfa', 'butuh_kewenangan_klinis'])
            ->mapWithKeys(fn (Izin $i) => [$i->kode => ['mfa' => $i->butuh_mfa, 'klinis' => $i->butuh_kewenangan_klinis]])->all();

        return [new HakAkses($menu, $izin, $label, $syarat), $detik];
    }

    /**
     * @param  Collection<array-key, Collection<array-key, Menu>>  $anak
     * @return list<string>
     */
    private function keturunan(string $id, Collection $anak): array
    {
        $hasil = [];
        foreach (($anak[$id] ?? collect())->keys() as $idAnak) {
            $hasil = [...$hasil, $idAnak, ...$this->keturunan($idAnak, $anak)];
        }

        return $hasil;
    }

    /** @param  Collection<string, Menu>  $semuaMenu */
    private function aktifSampaiAkar(string $id, Collection $semuaMenu): bool
    {
        for ($menu = $semuaMenu[$id] ?? null; $menu !== null; $menu = $semuaMenu[$menu->induk_id] ?? null) {
            if (! $menu->aktif) {
                return false;
            }
        }

        return isset($semuaMenu[$id]);
    }
}
