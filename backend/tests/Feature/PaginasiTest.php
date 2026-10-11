<?php

namespace Tests\Feature;

use App\Core\Http\Paginasi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PaginasiTest extends TestCase
{
    private const TABEL = 'inti.uji_paginasi';

    /** Waktu sengaja berbeda mikrodetik dan ada yang kembar, agar presisi dan pemecah seri (id) teruji. */
    private const BARIS = [
        ['id' => '01900000-0000-7000-8000-000000000001', 'dibuat_pada' => '2026-10-11 09:00:00.000001+07'],
        ['id' => '01900000-0000-7000-8000-000000000002', 'dibuat_pada' => '2026-10-11 09:00:00.000002+07'],
        ['id' => '01900000-0000-7000-8000-000000000003', 'dibuat_pada' => '2026-10-11 09:00:00.000002+07'],
        ['id' => '01900000-0000-7000-8000-000000000004', 'dibuat_pada' => '2026-10-11 09:00:00.000003+07'],
        ['id' => '01900000-0000-7000-8000-000000000005', 'dibuat_pada' => '2026-10-11 09:00:01+07'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $migrator = DB::connection('pgsql_migrator');
        $migrator->statement('CREATE TABLE IF NOT EXISTS '.self::TABEL.' (id uuid PRIMARY KEY, dibuat_pada timestamptz NOT NULL)');
        $this->beforeApplicationDestroyed(function () use ($migrator) {
            $migrator->statement('DROP TABLE IF EXISTS '.self::TABEL);
            $migrator->disconnect();
        });

        DB::table(self::TABEL)->insert(self::BARIS);

        // Endpoint contoh: hanya ada di tes.
        Route::middleware('api')->get('api/v1/uji-paginasi', fn (Request $request) => Paginasi::respons(
            UjiPaginasi::query(), $request, 'dibuat_pada',
            bentuk: fn ($baris) => $baris->pluck('id'),
        ));
    }

    public function test_kursor_berjalan_beberapa_halaman_tanpa_baris_hilang_atau_berulang(): void
    {
        $halaman1 = $this->getJson('/api/v1/uji-paginasi?batas=2')->assertOk();
        $this->assertSame([self::BARIS[4]['id'], self::BARIS[3]['id']], $halaman1->json('data'));
        $kursor = $halaman1->json('meta.kursor_berikutnya');
        $this->assertIsString($kursor);

        $halaman2 = $this->getJson('/api/v1/uji-paginasi?batas=2&kursor='.$kursor)->assertOk();
        $this->assertSame([self::BARIS[2]['id'], self::BARIS[1]['id']], $halaman2->json('data'));

        $halaman3 = $this->getJson('/api/v1/uji-paginasi?batas=2&kursor='.$halaman2->json('meta.kursor_berikutnya'))->assertOk();
        $this->assertSame([self::BARIS[0]['id']], $halaman3->json('data'));
        $this->assertNull($halaman3->json('meta.kursor_berikutnya'));
    }

    public function test_batas_bawaan_lima_puluh(): void
    {
        $this->getJson('/api/v1/uji-paginasi')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.kursor_berikutnya', null);
    }

    public function test_batas_di_atas_maksimum_ditolak_422(): void
    {
        $this->getJson('/api/v1/uji-paginasi?batas=200')->assertOk();

        $this->getJson('/api/v1/uji-paginasi?batas=201')
            ->assertStatus(422)
            ->assertJsonPath('galat.kode', 'ukuran_halaman_melebihi_batas');
    }

    public function test_batas_bukan_bilangan_positif_ditolak(): void
    {
        foreach (['0', '-1', 'abc', '2.5'] as $batas) {
            $this->getJson('/api/v1/uji-paginasi?batas='.$batas)
                ->assertStatus(422)
                ->assertJsonPath('galat.kode', 'validasi_gagal');
        }
    }

    public function test_kursor_rusak_ditolak(): void
    {
        foreach (['bukan-kursor', base64_encode('{"a":1}'), base64_encode('[1,2]')] as $kursor) {
            $this->getJson('/api/v1/uji-paginasi?kursor='.urlencode($kursor))
                ->assertStatus(422)
                ->assertJsonPath('galat.kode', 'kursor_tidak_valid');
        }
    }
}

/** Model dengan cast datetime: jalur yang biasanya menghilangkan mikrodetik. */
class UjiPaginasi extends Model
{
    protected $table = 'inti.uji_paginasi';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = ['dibuat_pada' => 'datetime'];
}
