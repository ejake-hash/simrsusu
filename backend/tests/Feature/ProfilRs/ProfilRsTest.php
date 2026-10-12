<?php

namespace Tests\Feature\ProfilRs;

use App\Core\ProfilRs\KontrasWarna;
use App\Core\ProfilRs\PemeriksaBerkas;
use App\Core\ProfilRs\ProfilRs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.8a-be: /profil-rs dan berkas paket identitas (kontrak v0.3.0, FR-54-08 langkah 2). */
class ProfilRsTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGP4z8AARAwQCgAf7gP9i18U1AAAAABJRU5ErkJggg==';

    private const ISIAN = [
        'nama' => 'RS Contoh Sintetis', 'kode_kemenkes' => '1234567', 'kelas' => 'B', 'status_blu' => true,
        'kode_ppk_bpjs' => '0000R001', 'alamat' => 'Jl. Contoh No. 1', 'warna' => ['brand' => '#1F5C99', 'accent' => '#D97706'],
        'kontak_admin' => ['unit' => 'Instalasi SIMRS', 'kontak' => 'ext. 1234'], 'dasar_perubahan' => 'Wizard langkah 2',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_instalasi_kosong_publik_tanpa_rinci_lalu_diisi_superadmin(): void
    {
        $this->mulaiSesiSpa();
        $kosong = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/profil-rs')->assertOk(), 'GET', '/profil-rs');
        $kosong->assertJsonPath('data.nama', null)->assertJsonPath('data.versi', '0')
            ->assertJsonPath('data.paket_identitas.warna', ['brand' => '#1F5C99', 'accent' => '#D97706'])
            ->assertJsonPath('data.kontak_admin.unit', 'admin SIMRS')->assertJsonPath('data.instalasi_demo', false)
            ->assertJsonMissingPath('data.rinci');
        $this->assertStringContainsString('Cookie', (string) $kosong->headers->get('Vary'));

        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);
        $isi = $this->mintaDenganVersi('PUT', '/api/v1/profil-rs', '0', self::ISIAN)->assertOk();
        $this->assertSesuaiKontrak($isi, 'PUT', '/profil-rs');
        $isi->assertJsonPath('data.nama', 'RS Contoh Sintetis')->assertJsonPath('data.rinci.kelas', 'B')->assertJsonPath('data.versi', '1');
        $this->assertAuditTerakhir('inti.profil_rs_diubah', (string) ProfilRs::satu()?->id);

        // Dengan izin: ada rinci; ETag berbeda dari bentuk publik.
        $rinci = $this->minta('GET', '/api/v1/profil-rs')->assertOk()->assertJsonPath('data.rinci.kode_ppk_bpjs', '0000R001');
        $this->mulaiSesiSpa();
        $publik = $this->minta('GET', '/api/v1/profil-rs')->assertOk()->assertJsonPath('data.nama', 'RS Contoh Sintetis')->assertJsonMissingPath('data.rinci');
        $this->assertNotSame($rinci->headers->get('ETag'), $publik->headers->get('ETag'));
        $this->assertStringNotContainsString('0000R001', (string) $publik->getContent());
    }

    public function test_rinci_hanya_untuk_izin_dan_sesi_sah(): void
    {
        $this->isiProfil();
        // Masuk tanpa izin profil: bentuk publik.
        $this->masukSebagai($this->buatPengguna());
        $this->minta('GET', '/api/v1/profil-rs')->assertOk()->assertJsonMissingPath('data.rinci');

        // Sesi superadmin yang sudah lewat batas diam: bentuk publik, bukan 401.
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);
        $this->minta('GET', '/api/v1/profil-rs')->assertJsonPath('data.rinci.kelas', 'B');
        $this->travel(20)->minutes();
        $this->minta('GET', '/api/v1/profil-rs')->assertOk()->assertJsonMissingPath('data.rinci');
    }

    public function test_kontras_kurang_ditolak_dengan_rasio(): void
    {
        $this->assertSame([], KontrasWarna::periksa(['brand' => '#1F5C99', 'accent' => '#D97706']), 'Paket contoh RS Contoh Pendidikan sah.');
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);

        $respons = $this->mintaDenganVersi('PUT', '/api/v1/profil-rs', '0', [...self::ISIAN, 'warna' => ['brand' => '#7FBF7F', 'accent' => '#4A4A4A']])
            ->assertStatus(422)->assertJsonPath('error.kode', 'kontras_kurang');
        $this->assertSesuaiKontrak($respons, 'PUT', '/profil-rs');
        $this->assertSame(['warna.brand', 'warna.accent'], array_column($respons->json('error.rincian'), 'kolom'));
        $this->assertLessThan(4.5, $respons->json('error.rincian.0.rasio'));
        $this->assertStringContainsString(':1', $respons->json('error.rincian.0.pesan'));
    }

    public function test_versi_if_match_semua_bentuk_dan_tanpa_izin(): void
    {
        $this->isiProfil();
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);

        $this->mintaDenganVersi('PUT', '/api/v1/profil-rs', '1-r', [...self::ISIAN, 'nama' => 'RS Dua'])->assertOk();
        $this->headerTambahan = ['If-Match' => 'W/"2"'];
        $this->minta('PUT', '/api/v1/profil-rs', [...self::ISIAN, 'nama' => 'RS Tiga'])->assertOk();
        $this->headerTambahan = [];
        $this->assertSesuaiKontrak($this->mintaDenganVersi('PUT', '/api/v1/profil-rs', '2', self::ISIAN)->assertStatus(412), 'PUT', '/profil-rs');
        $this->assertSesuaiKontrak($this->minta('PUT', '/api/v1/profil-rs', self::ISIAN)->assertStatus(428), 'PUT', '/profil-rs');

        $this->masukSebagai($this->buatPengguna());
        $this->assertSesuaiKontrak($this->mintaDenganVersi('PUT', '/api/v1/profil-rs', '3', self::ISIAN)->assertForbidden(), 'PUT', '/profil-rs');
    }

    public function test_paket_contoh_fiktif_lolos_pemeriksa_berkas(): void
    {
        // Paket identitas fiktif "RS Contoh Pendidikan" (repo publik): bisa diunggah apa adanya.
        $pemeriksa = app(PemeriksaBerkas::class);
        foreach (['logo' => 'logo-rs-contoh.svg', 'motif' => 'motif-contoh.svg'] as $jenis => $berkas) {
            $hasil = $pemeriksa->periksa($jenis, (string) file_get_contents(base_path("../docs/brand/contoh/$berkas")));
            $this->assertSame('image/svg+xml', $hasil['tipe'], $berkas);
        }
    }

    public function test_unggah_logo_tampil_publik_dan_svg_berskrip_ditolak(): void
    {
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);

        $logo = $this->unggah('logo', '0', 'logo.png', (string) base64_decode(self::PNG))->assertOk();
        $this->assertSesuaiKontrak($logo, 'PUT', '/profil-rs/berkas/{jenis}');
        $url = $logo->json('data.paket_identitas.logo_url');
        $this->assertStringStartsWith('/api/v1/profil-rs/berkas/logo?v=', $url);

        $this->mulaiSesiSpa();
        $berkas = $this->minta('GET', $url)->assertOk();
        $this->assertSame('image/png', $berkas->headers->get('Content-Type'));
        $this->assertSame('nosniff', $berkas->headers->get('X-Content-Type-Options'));
        $this->assertSame($url, $this->minta('GET', '/api/v1/profil-rs')->json('data.paket_identitas.logo_url'));

        $this->masukSebagai($superadmin, $rahasia);
        $versi = $logo->json('data.versi');
        foreach ([
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:x="http://www.w3.org/1999/xlink"><image x:href="https://contoh.invalid/a.png"/></svg>',
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div/></foreignObject></svg>',
            // Temuan tinjauan #38: UTF-16 menyembunyikan DOCTYPE dari pemeriksaan byte, PI stylesheet, url() di atribut
            // presentasi, escape CSS, dan elemen XHTML ber-namespace.
            mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE svg [<!ENTITY s "&#60;script&#62;alert(1)&#60;/script&#62;">]><svg xmlns="http://www.w3.org/2000/svg">&s;</svg>', 'UTF-16LE', 'UTF-8'),
            '<?xml-stylesheet href="https://contoh.invalid/a.css"?><svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(https://contoh.invalid/x#g)"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><style>rect{fill:\\75rl(https://contoh.invalid/a)}</style><rect/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml"><h:meta http-equiv="refresh" content="0;url=https://contoh.invalid"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg"><style>rect{fill:image-set("https://contoh.invalid/a.png" 1x)}</style><rect/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" xml:base="https://contoh.invalid/"><rect/></svg>',
        ] as $svg) {
            $this->assertSesuaiKontrak($this->unggah('logo', $versi, 'logo.svg', $svg)->assertStatus(422)->assertJsonPath('error.kode', 'berkas_tidak_aman'),
                'PUT', '/profil-rs/berkas/{jenis}');
        }
        $this->unggah('foto', $versi, 'foto.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>')
            ->assertStatus(422)->assertJsonPath('error.kode', 'tipe_berkas_tidak_diizinkan');
        $this->unggah('logo', $versi, 'logo.png', 'bukan gambar sama sekali')->assertStatus(422)->assertJsonPath('error.kode', 'tipe_berkas_tidak_diizinkan');
        config(['instalasi.identitas.batas_kb.logo' => 0]);
        $this->unggah('logo', $versi, 'logo.png', (string) base64_decode(self::PNG))->assertStatus(422)->assertJsonPath('error.kode', 'berkas_terlalu_besar');
        config(['instalasi.identitas.batas_kb.logo' => 200]);

        // 412 tidak meninggalkan berkas yatim.
        $this->unggah('logo', '999', 'logo.png', (string) base64_decode(self::PNG))->assertStatus(412);
        $this->assertCount(1, Storage::disk('local')->allFiles('identitas/logo'));

        $svg = $this->unggah('logo', $versi, 'logo.svg', '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<!-- dibuat editor --><svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"/></defs><rect fill="url(#g)" width="4" height="4"/></svg>')->assertOk();
        // Yang disimpan hasil serialisasi ulang DOM, bukan byte kiriman.
        $tersimpan = Storage::disk('local')->get(collect(Storage::disk('local')->allFiles('identitas/logo'))->first(fn ($p) => str_ends_with($p, '.svg')));
        $this->assertStringStartsWith('<svg', (string) $tersimpan);
        $this->mulaiSesiSpa();
        $berkasSvg = $this->minta('GET', $svg->json('data.paket_identitas.logo_url'))->assertOk();
        $this->assertSame('image/svg+xml', $berkasSvg->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", (string) $berkasSvg->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('immutable', (string) $berkasSvg->headers->get('Cache-Control'));
        $tanpaVersiCocok = (string) $this->minta('GET', '/api/v1/profil-rs/berkas/logo?v=lama')->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $tanpaVersiCocok);
        $this->assertStringNotContainsString('immutable', $tanpaVersiCocok);
        $this->minta('GET', '/api/v1/profil-rs/berkas/motif')->assertNotFound();
        $this->assertCount(2, Storage::disk('local')->allFiles('identitas/logo'), 'Berkas lama tidak dihapus.');
    }

    private function isiProfil(): void
    {
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);
        $this->mintaDenganVersi('PUT', '/api/v1/profil-rs', '0', self::ISIAN)->assertOk();
    }

    /** PUT multipart dengan cookie dan CSRF peramban (KlienSpa hanya mengirim JSON). */
    private function unggah(string $jenis, string $versi, string $nama, string $isi): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $berkas = UploadedFile::fake()->createWithContent($nama, $isi);
        $this->unencryptedCookies = $this->kue;
        $respons = $this->withCredentials()->withHeaders(array_filter([
            'Origin' => self::ORIGIN, 'Referer' => self::ORIGIN.'/', 'Accept' => 'application/json',
            'X-XSRF-TOKEN' => $this->kue['XSRF-TOKEN'] ?? null, 'If-Match' => '"'.$versi.'"',
        ]))->put("/api/v1/profil-rs/berkas/$jenis", ['berkas' => $berkas]);
        foreach ($respons->headers->getCookies() as $kue) {
            $kue->isCleared() ? $this->kue = array_diff_key($this->kue, [$kue->getName() => 1]) : $this->kue[$kue->getName()] = $kue->getValue();
        }

        return $respons;
    }
}
