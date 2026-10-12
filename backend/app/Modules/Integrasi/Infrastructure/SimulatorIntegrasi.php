<?php

namespace App\Modules\Integrasi\Infrastructure;

use App\Modules\Integrasi\Application\KelolaKredensial;
use App\Modules\Integrasi\Domain\SistemIntegrasi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Simulator sistem luar dalam repo (K8; tiket 1.9 butir 6), dipakai PanggilanHttp bila INTEGRASI_SIMULATOR aktif:
 * tidak ada permintaan yang keluar jaringan. Perilaku mengikuti catatan lapangan (perlu verifikasi): header BPJS
 * wajib dan tanda tangan berbentuk base64 (§1.1), `metaData`/`metadata` (§1.4), token OAuth2 SATUSEHAT (§15.2).
 * Mode per sistem untuk menguji ketahanan: `normal`, `mati` (tidak tersambung), `gangguan` (HTTP 503), `tolak`
 * (kredensial ditolak). Penangan kiriman memasang jawabannya sendiri lewat jawabanTambahan().
 */
final class SimulatorIntegrasi
{
    public const MODE = ['normal', 'mati', 'gangguan', 'tolak'];

    private const KUNCI_TOKEN = 'integrasi:simulator:satusehat:token';

    /** @var array<string, callable(string, string, array<string, mixed>): ?JawabanHttp> */
    private static array $tambahan = [];

    public static function aktif(): bool
    {
        return (bool) config('integrasi.simulator') && ! app()->isProduction();
    }

    public static function atur(SistemIntegrasi $sistem, string $mode): void
    {
        Cache::forever('integrasi:simulator:'.$sistem->value, $mode);
    }

    public static function mode(SistemIntegrasi $sistem): string
    {
        $mode = Cache::get('integrasi:simulator:'.$sistem->value);

        return in_array($mode, self::MODE, true) ? $mode : 'normal';
    }

    /**
     * Jawaban untuk path tertentu dari penangan kiriman (mis. Location SATUSEHAT di PR berikutnya).
     *
     * @param  callable(string $metode, string $path, array<string, mixed> $isi): ?JawabanHttp  $jawab
     */
    public static function jawabanTambahan(SistemIntegrasi $sistem, callable $jawab): void
    {
        self::$tambahan[$sistem->value] = $jawab;
    }

    /**
     * @param  array<string, string>  $header
     * @param  array<string, mixed>  $isi
     */
    public function jawab(SistemIntegrasi $sistem, string $lingkungan, string $metode, string $path, array $header, array $isi): JawabanHttp
    {
        $latensi = random_int(5, 40);
        $query = str_contains($path, '?') ? substr($path, strpos($path, '?') + 1) : '';
        $path = '/'.ltrim((string) strtok($path, '?'), '/');

        return match (self::mode($sistem)) {
            'mati' => new JawabanHttp(null, null, $latensi, 'Tidak tersambung.'),
            'gangguan' => new JawabanHttp(503, '<html><body>Service Unavailable</body></html>', $latensi, null),
            'tolak' => $sistem === SistemIntegrasi::Satusehat
                ? self::json(401, ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'], $latensi)
                : self::json(200, ['metaData' => ['code' => '401', 'message' => 'Unauthorized']], $latensi),
            default => $this->normal($sistem, $lingkungan, $metode, $path, $query, $header, $isi, $latensi),
        };
    }

    /**
     * @param  array<string, string>  $header
     * @param  array<string, mixed>  $isi
     */
    private function normal(SistemIntegrasi $sistem, string $lingkungan, string $metode, string $path, string $query, array $header, array $isi, int $latensi): JawabanHttp
    {
        if ($sistem->kodeBpjs()) {
            foreach (['X-cons-id', 'X-timestamp', 'X-signature', 'user_key'] as $h) {
                if (($header[$h] ?? '') === '') {
                    return self::json(200, ['metaData' => ['code' => '401', 'message' => "Header $h wajib"]], $latensi);
                }
            }
            if (base64_decode($header['X-signature'], true) === false || strlen(base64_decode($header['X-signature'])) !== 32) {
                return self::json(200, ['metaData' => ['code' => '401', 'message' => 'Signature tidak valid']], $latensi);
            }
        }
        $tambahan = self::$tambahan[$sistem->value] ?? null;
        if ($tambahan !== null && ($j = $tambahan($metode, $path, $isi)) !== null) {
            return $j;
        }

        if ($sistem === SistemIntegrasi::BpjsVclaim && $metode === 'GET') {
            return $this->vclaim($lingkungan, $path, $header, $latensi);
        }
        if ($sistem === SistemIntegrasi::Aplicares && ($j = $this->aplicares($lingkungan, $metode, $path, $isi, $latensi)) !== null) {
            return $j;
        }

        if ($sistem === SistemIntegrasi::Satusehat && ($j = $this->satusehat($lingkungan, $metode, $path, $query, $header, $isi, $latensi)) !== null) {
            return $j;
        }

        return self::json(404, ['metaData' => ['code' => '404', 'message' => 'Simulator: path tidak dikenal']], $latensi);
    }

    /**
     * SATUSEHAT (catatan §15–§19, perlu verifikasi): token OAuth2 yang diingat simulator; FHIR dan KFA wajib bearer token
     * yang masih berlaku (401 bila kedaluwarsa/dicabut). Location/Organization: POST (identifier sama → OperationOutcome
     * duplikat 20002), PUT, dan pencarian `?identifier=system|value`; partOf harus menunjuk resource yang sudah ada.
     * Patient/Practitioner sintetis lewat NIK. Bentuk OperationOutcome duplikat dan respons KFA adalah asumsi simulator.
     *
     * @param  array<string, string>  $header
     * @param  array<string, mixed>  $isi
     */
    private function satusehat(string $lingkungan, string $metode, string $path, string $query, array $header, array $isi, int $latensi): ?JawabanHttp
    {
        if ($metode === 'POST' && $path === '/oauth2/v1/accesstoken') {
            if (($isi['client_id'] ?? '') === '' || ($isi['client_secret'] ?? '') === '') {
                return self::json(401, ['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'], $latensi);
            }
            $token = Str::random(40);
            Cache::forever(self::KUNCI_TOKEN, [...(array) Cache::get(self::KUNCI_TOKEN, []), hash('sha256', $token)]);

            return self::json(200, ['access_token' => $token, 'expires_in' => '14399', 'token_type' => 'BearerToken', 'status' => 'approved'], $latensi);
        }
        if (preg_match('#^/(fhir-r4/v1|kfa-v2)/#', $path) !== 1) {
            return null;
        }
        $token = str_starts_with($header['Authorization'] ?? '', 'Bearer ') ? substr($header['Authorization'], 7) : '';
        if (! in_array(hash('sha256', $token), (array) Cache::get(self::KUNCI_TOKEN, []), true)) {
            return self::hasil(401, 'security', 'Access token invalid or expired', $latensi);
        }
        parse_str($query, $q);
        if ($path === '/kfa-v2/products/all' && $metode === 'GET') {
            $kata = strtoupper((string) ($q['keyword'] ?? ''));
            $produk = array_values(array_filter([
                ['kfa_code' => '93000001', 'name' => 'PARASETAMOL SINTETIS 500 MG TABLET', 'active' => true],
                ['kfa_code' => '93000002', 'name' => 'AMOKSISILIN SINTETIS 500 MG KAPSUL', 'active' => true],
            ], fn (array $p) => $kata !== '' && str_contains($p['name'], $kata)));

            return self::json(200, ['total' => count($produk), 'page' => 1, 'size' => (int) ($q['size'] ?? 15), 'items' => ['data' => $produk]], $latensi);
        }
        $segmen = explode('/', substr($path, strlen('/fhir-r4/v1/')));
        [$jenis, $id] = [$segmen[0], $segmen[1] ?? null];
        if ($metode === 'GET' && $id === null) {
            return $this->cariFhir($lingkungan, $jenis, (string) ($q['identifier'] ?? ''), $latensi);
        }
        if (in_array($jenis, ['Location', 'Organization'], true) && (($metode === 'POST' && $id === null) || ($metode === 'PUT' && $id !== null))) {
            return $this->simpanFhir($lingkungan, $jenis, $id, $isi, $latensi);
        }

        return self::hasil(404, 'not-found', 'Simulator: path tidak dikenal', $latensi);
    }

    private function cariFhir(string $lingkungan, string $jenis, string $identifier, int $latensi): JawabanHttp
    {
        [$sistem, $nilai] = array_pad(explode('|', $identifier, 2), 2, '');
        $ketemu = [];
        if (in_array($jenis, ['Patient', 'Practitioner'], true)) {
            $sintetis = ['Patient' => ['9900000000000001' => ['P99000000001', 'PASIEN SINTETIS']],
                'Practitioner' => ['9900000000000002' => ['N99000000002', 'DOKTER SINTETIS']]];
            if ($sistem === config('integrasi.satusehat.identifier_nik') && isset($sintetis[$jenis][$nilai])) {
                [$ihs, $nama] = $sintetis[$jenis][$nilai];
                $ketemu[] = ['resourceType' => $jenis, 'id' => $ihs, 'identifier' => [['system' => $sistem, 'value' => $nilai]], 'name' => [['text' => $nama]]];
            }
        } else {
            $ketemu = array_values(array_filter(self::resourceSatusehat($lingkungan, $jenis),
                fn (array $r) => ($r['identifier'][0]['system'] ?? null) === $sistem && ($r['identifier'][0]['value'] ?? null) === $nilai));
        }

        return self::json(200, ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => count($ketemu),
            'entry' => array_map(fn (array $r) => ['fullUrl' => "$jenis/{$r['id']}", 'resource' => $r], $ketemu)], $latensi);
    }

    /** @param  array<string, mixed>  $isi */
    private function simpanFhir(string $lingkungan, string $jenis, ?string $id, array $isi, int $latensi): JawabanHttp
    {
        $semua = self::resourceSatusehat($lingkungan, $jenis);
        $identifier = $isi['identifier'][0] ?? null;
        $tidakValid = match (true) {
            ($isi['resourceType'] ?? null) !== $jenis => 'resourceType tidak sesuai',
            ! is_array($identifier) || ! str_starts_with((string) ($identifier['system'] ?? ''), 'http://sys-ids.kemkes.go.id/') || ($identifier['value'] ?? '') === '' => 'identifier wajib (sys-ids)',
            ! is_string($isi['name'] ?? null) || $isi['name'] === '' => 'name wajib',
            $jenis === 'Location' && ! in_array($isi['physicalType']['coding'][0]['code'] ?? null, ['si', 'bu', 'wi', 'lvl', 'ro', 'bd'], true) => 'physicalType tidak dikenal',
            $id !== null && ! isset($semua[$id]) => null,
            $id !== null && ($isi['id'] ?? null) !== $id => 'id isi tidak sama dengan id path',
            default => $this->rujukanTidakAda($lingkungan, $jenis, $isi),
        };
        if ($id !== null && ! isset($semua[$id])) {
            return self::hasil(404, 'not-found', "$jenis/$id tidak ditemukan", $latensi);
        }
        if ($tidakValid !== null) {
            return self::hasil(400, 'invalid', $tidakValid.' (20013)', $latensi);
        }
        foreach ($semua as $lain => $r) {
            if ($lain !== $id && $r['identifier'][0] == $identifier) {
                return self::json(400, ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => 'duplicate',
                    'details' => ['text' => 'duplicate'], 'diagnostics' => '20002']]], $latensi);
            }
        }
        $id ??= (string) Str::uuid();
        $semua[$id] = [...$isi, 'id' => $id, 'meta' => ['lastUpdated' => now()->toIso8601String(), 'versionId' => (string) Str::uuid()]];
        Cache::forever(self::kunciResource($lingkungan, $jenis), $semua);

        return self::json(isset($isi['id']) ? 200 : 201, $semua[$id], $latensi);
    }

    /** @param  array<string, mixed>  $isi */
    private function rujukanTidakAda(string $lingkungan, string $jenis, array $isi): ?string
    {
        $org = app(KelolaKredensial::class)->nilai(SistemIntegrasi::Satusehat, $lingkungan)['organization_id'] ?? null;
        $rujukan = array_filter([$isi['partOf']['reference'] ?? null, $isi['managingOrganization']['reference'] ?? null]);
        foreach ($rujukan as $r) {
            [$jenisRujukan, $idRujukan] = array_pad(explode('/', (string) $r, 2), 2, '');
            if (! ($jenisRujukan === 'Organization' && $idRujukan === $org) && ! isset(self::resourceSatusehat($lingkungan, $jenisRujukan)[$idRujukan])) {
                return "rujukan $r tidak ditemukan";
            }
        }

        return null;
    }

    /**
     * Resource Location/Organization yang tersimpan di simulator (untuk tes dan pemeriksaan lokal).
     *
     * @return array<string, array<string, mixed>> id => resource
     */
    public static function resourceSatusehat(string $lingkungan, string $jenis): array
    {
        $isi = Cache::get(self::kunciResource($lingkungan, $jenis));

        return is_array($isi) ? $isi : [];
    }

    /** Semua token SATUSEHAT yang pernah diterbitkan simulator menjadi tidak berlaku (meniru token kedaluwarsa). */
    public static function cabutTokenSatusehat(): void
    {
        Cache::forget(self::KUNCI_TOKEN);
    }

    private static function kunciResource(string $lingkungan, string $jenis): string
    {
        return "integrasi:simulator:satusehat:$lingkungan:$jenis";
    }

    private static function hasil(int $kode, string $kodeIssue, string $teks, int $latensi): JawabanHttp
    {
        return self::json($kode, ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'code' => $kodeIssue, 'details' => ['text' => $teks]]]], $latensi);
    }

    /**
     * VClaim baca dengan `response` terenkripsi memakai kredensial tersimpan dan X-timestamp permintaan (catatan §1.3).
     * Data sintetis: peserta 9990000000001 / NIK 9900000000000001; rujukan SIMFKTP001 (FKTP) dan SIMRS001 (hanya di RS,
     * dibungkus array). Kode untuk data tidak ditemukan ("201") adalah asumsi simulator, perlu verifikasi.
     *
     * @param  array<string, string>  $header
     */
    private function vclaim(string $lingkungan, string $path, array $header, int $latensi): JawabanHttp
    {
        $peserta = ['noKartu' => '9990000000001', 'nik' => '9900000000000001', 'nama' => 'PESERTA SINTETIS', 'sex' => 'P', 'tglLahir' => '1990-01-01',
            'hakKelas' => ['kode' => '3', 'keterangan' => 'KELAS III'], 'jenisPeserta' => ['kode' => '0', 'keterangan' => 'SINTETIS'],
            'statusPeserta' => ['kode' => '0', 'keterangan' => 'AKTIF']];
        $rujukan = fn (string $no) => ['noKunjungan' => $no, 'tglKunjungan' => '2026-10-01', 'provPerujuk' => ['kode' => 'SIM0001', 'nama' => 'FASKES SINTETIS'],
            'peserta' => $peserta, 'diagnosa' => ['kode' => 'Z00.0', 'nama' => 'SINTETIS'], 'poliRujukan' => ['kode' => 'INT', 'nama' => 'PENYAKIT DALAM']];
        $isi = match (true) {
            $path === '/referensi/propinsi' => ['list' => [['kode' => '99', 'nama' => 'PROVINSI SINTETIS']]],
            preg_match('#^/referensi/#', $path) === 1 => ['list' => []],
            preg_match('#^/Peserta/(nik/9900000000000001|nokartu/9990000000001)/tglSEP/#', $path) === 1 => ['peserta' => $peserta],
            $path === '/Rujukan/SIMFKTP001' => ['rujukan' => $rujukan('SIMFKTP001')],
            $path === '/Rujukan/RS/SIMRS001' => ['rujukan' => [$rujukan('SIMRS001')]],
            default => null,
        };
        if ($isi === null) {
            return self::json(200, ['metaData' => ['code' => '201', 'message' => 'Data tidak ditemukan'], 'response' => null], $latensi);
        }
        $kredensial = app(KelolaKredensial::class)->nilai(SistemIntegrasi::BpjsVclaim, $lingkungan) ?? [];
        if (($kredensial['cons_id'] ?? null) !== $header['X-cons-id']) {
            return self::json(200, ['metaData' => ['code' => '401', 'message' => 'Consumer ID tidak dikenal'], 'response' => null], $latensi);
        }

        return self::json(200, ['metaData' => ['code' => '200', 'message' => 'Sukses'],
            'response' => KlienVclaim::tutup($isi, $kredensial, (int) $header['X-timestamp'])], $latensi);
    }

    /**
     * Aplicares (catatan §24): ref/kelas, bed/update (ruang harus sudah terdaftar), bed/create (ruang belum terdaftar).
     * Ruang disimpan per lingkungan + kode PPK dengan kunci kodekelas|koderuang. Kode sukses `1` dan kode penolakan
     * `0` adalah asumsi simulator (perlu verifikasi); daftar kelas sintetis. Kodekelas berawalan `TOLAK` selalu ditolak
     * (untuk menguji penolakan per ruang).
     *
     * @param  array<string, mixed>  $isi
     */
    private function aplicares(string $lingkungan, string $metode, string $path, array $isi, int $latensi): ?JawabanHttp
    {
        if ($metode === 'GET' && $path === '/ref/kelas') {
            return self::json(200, ['metadata' => ['code' => 1, 'message' => 'OK'], 'response' => ['list' => [
                ['kodekelas' => 'SIM1', 'namakelas' => 'KELAS SINTETIS 1'], ['kodekelas' => 'SIM3', 'namakelas' => 'KELAS SINTETIS 3']]]], $latensi);
        }
        if ($metode !== 'POST' || preg_match('#^/bed/(update|create)/([A-Za-z0-9]+)$#', $path, $m) !== 1) {
            return null;
        }
        $tolak = fn (string $pesan) => self::json(200, ['metadata' => ['code' => 0, 'message' => $pesan]], $latensi);
        foreach (['kodekelas', 'koderuang', 'namaruang'] as $k) {
            if (! is_string($isi[$k] ?? null) || $isi[$k] === '') {
                return $tolak("$k wajib diisi");
            }
        }
        foreach (['kapasitas', 'tersedia', 'tersediapria', 'tersediawanita', 'tersediapriawanita'] as $k) {
            if (! is_int($isi[$k] ?? null) || $isi[$k] < 0) {
                return $tolak("$k harus bilangan bulat ≥ 0");
            }
        }
        if (str_starts_with($isi['kodekelas'], 'TOLAK')) {
            return $tolak('Kode kelas tidak dikenal');
        }
        if ($isi['tersedia'] > $isi['kapasitas']) {
            return $tolak('tersedia melebihi kapasitas');
        }
        $ruang = self::ruangAplicares($lingkungan, $m[2]);
        $kunci = $isi['kodekelas'].'|'.$isi['koderuang'];
        if ($m[1] === 'update' && ! isset($ruang[$kunci])) {
            return $tolak('Ruangan belum terdaftar');
        }
        if ($m[1] === 'create' && isset($ruang[$kunci])) {
            return $tolak('Ruangan sudah terdaftar');
        }
        $ruang[$kunci] = $isi;
        Cache::forever(self::kunciAplicares($lingkungan, $m[2]), $ruang);

        return self::json(200, ['metadata' => ['code' => 1, 'message' => 'OK']], $latensi);
    }

    /**
     * Ruang yang terdaftar di simulator Aplicares (untuk tes dan pemeriksaan lokal).
     *
     * @return array<string, array<string, mixed>> kodekelas|koderuang => isi terakhir
     */
    public static function ruangAplicares(string $lingkungan, string $kodePpk): array
    {
        $ruang = Cache::get(self::kunciAplicares($lingkungan, $kodePpk));

        return is_array($ruang) ? $ruang : [];
    }

    private static function kunciAplicares(string $lingkungan, string $kodePpk): string
    {
        return "integrasi:simulator:aplicares:$lingkungan:$kodePpk";
    }

    /** @param  array<string, mixed>  $isi */
    private static function json(int $kode, array $isi, int $latensi): JawabanHttp
    {
        return new JawabanHttp($kode, (string) json_encode($isi), $latensi, null);
    }
}
