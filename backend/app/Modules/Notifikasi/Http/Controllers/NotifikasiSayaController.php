<?php

namespace App\Modules\Notifikasi\Http\Controllers;

use App\Core\Audit\CatatAktivitas;
use App\Core\Http\ApiError;
use App\Core\Http\EtagIsi;
use App\Core\Http\Paginasi;
use App\Modules\Akses\Models\Pengguna;
use App\Modules\Notifikasi\Application\PendengarKejadian;
use App\Modules\Notifikasi\Domain\PengirimPesanSingkat;
use App\Modules\Notifikasi\Http\Resources\NotifikasiResource;
use App\Modules\Notifikasi\Models\JenisTugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** /api/v1/saya/notifikasi* dan /saya/preferensi-notifikasi (kontrak v0.7.0, tiket 1.7). Hanya data akun yang masuk. */
final class NotifikasiSayaController
{
    public function index(Request $request): Response
    {
        $filter = $request->validate(['status' => ['nullable', Rule::in(['belum_dibaca', 'semua'])]]);
        /** @var Pengguna $saya */
        $saya = $request->user();
        $query = DB::table('notifikasi.notifikasi')->where('pengguna_id', $saya->id)
            ->when(($filter['status'] ?? 'semua') === 'belum_dibaca', fn ($q) => $q->whereNull('dibaca_pada'));
        $respons = Paginasi::respons($query, $request, 'dibuat_pada', bentuk: fn ($baris) => $baris->map(fn ($n) => NotifikasiResource::notifikasi($n))->values()->all());

        return EtagIsi::terapkan($request, EtagIsi::tambahMeta($respons, [
            'jumlah_belum_dibaca' => DB::table('notifikasi.notifikasi')->where('pengguna_id', $saya->id)->whereNull('dibaca_pada')->count(),
        ]));
    }

    public function dibaca(Request $request, string $notifikasi): JsonResponse
    {
        $query = fn () => DB::table('notifikasi.notifikasi')->where('pengguna_id', $request->user()->id)->where('id', $notifikasi);
        $query()->whereNull('dibaca_pada')->update(['dibaca_pada' => now()]);
        $baris = $query()->first() ?? throw new ApiError(404, 'tidak_ditemukan', 'Data tidak ditemukan.');

        return new JsonResponse(['data' => NotifikasiResource::notifikasi($baris)]);
    }

    /** Notifikasi kritis tidak ikut ditandai (dibuka satu per satu, PRD 5.1 butir 12). */
    public function semuaDibaca(Request $request): JsonResponse
    {
        $isi = $request->validate(['sampai' => ['nullable', 'date']]);
        $jumlah = DB::table('notifikasi.notifikasi')->where('pengguna_id', $request->user()->id)->whereNull('dibaca_pada')
            ->where('prioritas', '!=', 'kritis')
            ->when($isi['sampai'] ?? null, fn ($q, $sampai) => $q->where('dibuat_pada', '<=', Carbon::parse($sampai)->format('Y-m-d H:i:s.uP')))
            ->update(['dibaca_pada' => now()]);

        return new JsonResponse(['data' => ['jumlah' => $jumlah]]);
    }

    public function preferensi(Request $request, PengirimPesanSingkat $pesanSingkat): JsonResponse
    {
        return new JsonResponse(['data' => $this->daftarPreferensi($request->user(), $pesanSingkat)]);
    }

    public function aturPreferensi(Request $request, PengirimPesanSingkat $pesanSingkat, CatatAktivitas $audit): JsonResponse
    {
        $isi = $request->validate([
            'preferensi' => ['required', 'array', 'max:100'],
            'preferensi.*.jenis' => ['required', 'string', 'max:60'],
            'preferensi.*.email' => ['sometimes', 'boolean'],
            'preferensi.*.pesan_singkat' => ['sometimes', 'boolean'],
        ]);
        /** @var Pengguna $saya */
        $saya = $request->user();
        $sekarang = $this->daftarPreferensi($saya, $pesanSingkat);
        $perJenis = collect($sekarang)->keyBy('jenis');

        DB::transaction(function () use ($isi, $perJenis, $saya, $audit) {
            foreach ($isi['preferensi'] as $p) {
                $ada = $perJenis->get($p['jenis']) ?? throw new ApiError(422, 'jenis_tidak_dikenal', 'Jenis notifikasi tidak dikenal.', [['kolom' => 'preferensi', 'pesan' => 'Jenis tidak dikenal.']]);
                $baru = [];
                foreach (['email', 'pesan_singkat'] as $kanal) {
                    if (! array_key_exists($kanal, $p) || $p[$kanal] === $ada[$kanal]['aktif']) {
                        continue;
                    }
                    if ($ada['kritis']) {
                        throw new ApiError(422, 'notifikasi_kritis', 'Notifikasi kritis tidak bisa dimatikan.', [['kolom' => 'preferensi', 'pesan' => 'Jenis ini kritis.']]);
                    }
                    if (! $ada[$kanal]['dapat_diubah']) {
                        throw new ApiError(422, 'kanal_tidak_tersedia', 'Kanal ini belum tersedia untuk akun Anda.', [['kolom' => 'preferensi', 'pesan' => "Kanal $kanal belum tersedia."]]);
                    }
                    $baru[$kanal] = $p[$kanal];
                }
                if ($baru !== []) {
                    DB::table('notifikasi.preferensi')->upsert([[
                        'id' => (string) Str::uuid7(), 'pengguna_id' => $saya->id, 'jenis' => $p['jenis'],
                        'email' => $baru['email'] ?? $ada['email']['aktif'], 'pesan_singkat' => $baru['pesan_singkat'] ?? $ada['pesan_singkat']['aktif'],
                        'dibuat_oleh' => $saya->id, 'diubah_oleh' => $saya->id, 'diubah_pada' => now(),
                    ]], ['pengguna_id', 'jenis'], [...array_keys($baru), 'diubah_oleh', 'diubah_pada']);
                }
            }
            $audit->catat('notifikasi.preferensi_diubah', $saya->id, 'akses.pengguna', $saya->id, ['preferensi' => $isi['preferensi']]);
        });

        return new JsonResponse(['data' => $this->daftarPreferensi($saya, $pesanSingkat)]);
    }

    /** @return list<array<string, mixed>> */
    private function daftarPreferensi(Pengguna $saya, PengirimPesanSingkat $pesanSingkat): array
    {
        $jenis = JenisTugas::query()->where('aktif', true)->orderBy('kode')->get(['kode', 'nama', 'kritis'])
            ->map(fn (JenisTugas $j) => ['kode' => $j->kode, 'nama' => $j->nama, 'kritis' => $j->kritis])
            ->prepend(['kode' => PendengarKejadian::AKUN_TERKUNCI, 'nama' => 'Akun terkunci', 'kritis' => true]);
        $pref = DB::table('notifikasi.preferensi')->where('pengguna_id', $saya->id)->get()->keyBy('jenis');
        $punyaEmail = is_string($saya->email) && $saya->email !== '';
        $smsAda = $pesanSingkat->tersedia();

        return $jenis->map(function (array $j) use ($pref, $punyaEmail, $smsAda) {
            $p = $pref->get($j['kode']);

            return [
                'jenis' => $j['kode'], 'nama' => $j['nama'], 'kritis' => $j['kritis'], 'in_app' => true,
                'email' => ['aktif' => $punyaEmail && ($j['kritis'] || (bool) ($p->email ?? true)), 'dapat_diubah' => $punyaEmail && ! $j['kritis']],
                'pesan_singkat' => ['aktif' => $smsAda && ($j['kritis'] || (bool) ($p->pesan_singkat ?? false)), 'dapat_diubah' => $smsAda && ! $j['kritis']],
            ];
        })->values()->all();
    }
}
