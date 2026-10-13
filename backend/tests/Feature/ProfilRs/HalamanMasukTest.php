<?php

namespace Tests\Feature\ProfilRs;

use App\Core\ProfilRs\ProfilRs;
use App\Core\ProfilRs\TeksHalamanMasuk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\AksesUji;
use Tests\Support\KlienSpa;
use Tests\Support\KontrakApi;
use Tests\TestCase;

/** Tiket 1.15-be: halaman masuk dapat diatur (kontrak v0.15.0) dan foto panel 16:10 tanpa metadata. */
class HalamanMasukTest extends TestCase
{
    use AksesUji, KlienSpa, KontrakApi;

    private const ISIAN = [
        'nama' => 'RS Contoh Sintetis', 'kode_kemenkes' => null, 'kelas' => 'B', 'status_blu' => null,
        'kode_ppk_bpjs' => null, 'alamat' => null, 'warna' => ['brand' => '#006937', 'accent' => '#F3C300'],
        'kontak_admin' => ['unit' => 'Instalasi SIMRS', 'kontak' => null], 'dasar_perubahan' => 'Tiket 1.15',
    ];

    private const TEKS = [
        'lencana' => 'Rumah sakit pendidikan', 'judul' => 'Melayani dengan', 'judul_sorot' => 'sepenuh hati.',
        'uraian' => 'Satu sistem untuk seluruh layanan.', 'fitur' => [
            ['judul' => 'Cepat', 'uraian' => 'Pendaftaran singkat.'], ['judul' => 'Aman', 'uraian' => 'Data terlindungi.'],
            ['judul' => 'Terhubung', 'uraian' => 'BPJS dan SATUSEHAT.'],
        ], 'foto_keterangan' => 'Gedung utama', 'tampilkan_motif' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);
    }

    private function versi(): string
    {
        return (string) $this->minta('GET', '/api/v1/profil-rs')->json('data.versi');
    }

    /** @param  array<string, mixed>  $tambahan */
    private function simpan(array $tambahan): TestResponse
    {
        return $this->mintaDenganVersi('PUT', '/api/v1/profil-rs', $this->versi(), [...self::ISIAN, ...$tambahan]);
    }

    public function test_instalasi_kosong_mengirim_teks_bawaan_dan_warna_pedoman(): void
    {
        $this->mulaiSesiSpa();
        $publik = $this->assertSesuaiKontrak($this->minta('GET', '/api/v1/profil-rs')->assertOk(), 'GET', '/profil-rs');
        $publik->assertJsonPath('data.halaman_masuk', [...ProfilRs::HALAMAN_MASUK_BAWAAN, 'bawaan' => true])
            ->assertJsonPath('data.paket_identitas.warna', ['brand' => '#006937', 'accent' => '#F3C300']);
    }

    public function test_simpan_teks_biasa_lalu_kembali_ke_bawaan(): void
    {
        $simpan = $this->simpan(['halaman_masuk' => [...self::TEKS,
            'judul' => "  <script>alert(1)</script>\n  Melayani\u{200B}   dengan ",
            'judul_sorot' => '   ', 'foto_keterangan' => "Gedung\tutama",
        ]])->assertOk();
        $this->assertSesuaiKontrak($simpan, 'PUT', '/profil-rs');
        $simpan->assertJsonPath('data.halaman_masuk.judul', '<script>alert(1)</script> Melayani dengan')
            ->assertJsonPath('data.halaman_masuk.judul_sorot', null)
            ->assertJsonPath('data.halaman_masuk.foto_keterangan', 'Gedung utama')
            ->assertJsonPath('data.halaman_masuk.tampilkan_motif', false)
            ->assertJsonPath('data.halaman_masuk.bawaan', false);
        $audit = json_decode($this->assertAuditTerakhir('inti.profil_rs_diubah', (string) ProfilRs::satu()?->id)->sesudah, true);
        $this->assertSame('Cepat', $audit['halaman_masuk']['fitur'][0]['judul']);

        // Publik melihat teks yang sama, tanpa build ulang.
        $this->mulaiSesiSpa();
        $this->minta('GET', '/api/v1/profil-rs')->assertJsonPath('data.halaman_masuk.lencana', 'Rumah sakit pendidikan');

        // Tidak dikirim = tetap; null = kembali ke bawaan.
        [$superadmin, $rahasia] = $this->superadminBerMfa();
        $this->masukSebagai($superadmin, $rahasia);
        $this->simpan([])->assertOk()->assertJsonPath('data.halaman_masuk.lencana', 'Rumah sakit pendidikan');
        $this->simpan(['halaman_masuk' => null])->assertOk()
            ->assertJsonPath('data.halaman_masuk', [...ProfilRs::HALAMAN_MASUK_BAWAAN, 'bawaan' => true]);
    }

    public function test_batas_panjang_sesudah_dibersihkan_dan_bentuk_fitur(): void
    {
        // 60 karakter + spasi berlebih: lolos karena dihitung sesudah dibersihkan (code point, bukan byte).
        $this->simpan(['halaman_masuk' => [...self::TEKS, 'lencana' => '  '.str_repeat('é', 60).'   ']])->assertOk();

        $salah = $this->simpan(['halaman_masuk' => [...self::TEKS, 'lencana' => str_repeat('a', 61),
            'fitur' => [...array_slice(self::TEKS['fitur'], 0, 2), ['judul' => str_repeat('b', 31), 'uraian' => 'x']]]])
            ->assertStatus(422)->assertJsonPath('error.kode', 'validasi_gagal');
        $this->assertEqualsCanonicalizing(['halaman_masuk.lencana', 'halaman_masuk.fitur.2.judul'], array_unique(array_column($salah->json('error.rincian'), 'kolom')));

        $this->simpan(['halaman_masuk' => [...self::TEKS, 'fitur' => array_slice(self::TEKS['fitur'], 0, 2)]])->assertStatus(422);
        $this->simpan(['halaman_masuk' => [...self::TEKS, 'judul' => "\u{200B} \n"]])->assertStatus(422);
        $this->simpan(['halaman_masuk' => [...self::TEKS, 'html' => '<b>x</b>']])->assertStatus(422);
        $this->simpan(['halaman_masuk' => 'teks'])->assertStatus(422);
    }

    public function test_pembersih_teks(): void
    {
        $this->assertSame(['judul' => 'a b', 'judul_sorot' => null, 'fitur' => [['judul' => 'c d']]],
            TeksHalamanMasuk::bersihkan(['judul' => "a\r\n\u{2028}b\u{0007}", 'judul_sorot' => "\u{200E} ", 'fitur' => [['judul' => " c\u{00A0}d "]]]));
        $this->assertSame('bukan array', TeksHalamanMasuk::bersihkan('bukan array'));
    }

    public function test_foto_dipotong_16_10_dan_metadata_dibuang(): void
    {
        // JPEG 400×400 dengan segmen APP1 Exif berisi penanda GPS sintetis.
        $gambar = imagecreatetruecolor(400, 400);
        imagefilledrectangle($gambar, 0, 0, 399, 399, (int) imagecolorallocate($gambar, 0, 105, 55));
        ob_start();
        imagejpeg($gambar);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\0\0".'GPS-SINTETIS-3.5952,98.6722';
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $this->assertStringContainsString('GPS-SINTETIS', $jpeg);

        $respons = $this->unggahFoto($jpeg)->assertOk();
        $paket = ProfilRs::satu()?->paket_identitas;
        $tersimpan = Storage::disk('local')->get($paket['foto']['path']);
        $ukuran = getimagesizefromstring((string) $tersimpan);
        $this->assertSame([400, 250], [$ukuran[0], $ukuran[1]]);
        $this->assertSame('image/jpeg', $ukuran['mime']);
        $this->assertStringNotContainsString('GPS-SINTETIS', (string) $tersimpan);
        $this->assertStringNotContainsString('Exif', (string) $tersimpan);
        $this->assertSame(hash('sha256', (string) $tersimpan), $paket['foto']['sha256']);
        $this->assertNotNull($respons->json('data.paket_identitas.foto_url'));

        // Lebar (gambar 800×200) dipotong kiri-kanan; PNG tetap PNG.
        $lebar = imagecreatetruecolor(800, 200);
        ob_start();
        imagepng($lebar);
        $this->unggahFoto((string) ob_get_clean(), 'foto.png')->assertOk();
        $ukuran = getimagesizefromstring((string) Storage::disk('local')->get(ProfilRs::satu()?->paket_identitas['foto']['path']));
        $this->assertSame([320, 200, 'image/png'], [$ukuran[0], $ukuran[1], $ukuran['mime']]);
    }

    private function unggahFoto(string $isi, string $nama = 'foto.jpg'): TestResponse
    {
        $versi = $this->versi();
        $this->app['auth']->forgetGuards();
        $this->unencryptedCookies = $this->kue;
        $respons = $this->withCredentials()->withHeaders(array_filter([
            'Origin' => self::ORIGIN, 'Referer' => self::ORIGIN.'/', 'Accept' => 'application/json',
            'X-XSRF-TOKEN' => $this->kue['XSRF-TOKEN'] ?? null, 'If-Match' => '"'.$versi.'"',
        ]))->put('/api/v1/profil-rs/berkas/foto', ['berkas' => UploadedFile::fake()->createWithContent($nama, $isi)]);
        foreach ($respons->headers->getCookies() as $kue) {
            $kue->isCleared() ? $this->kue = array_diff_key($this->kue, [$kue->getName() => 1]) : $this->kue[$kue->getName()] = $kue->getValue();
        }

        return $respons;
    }
}
