<?php

namespace App\Modules\Akses\Database\Factories;

use App\Modules\Akses\Models\Pengguna;
use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Data sintetis saja. @extends Factory<Pengguna> */
final class PenggunaFactory extends Factory
{
    protected $model = Pengguna::class;

    public function definition(): array
    {
        return [
            'pegawai_id' => Pegawai::factory(),
            'nama_pengguna' => 'sintetis.'.fake()->unique()->numerify('######'),
            'surel' => fake()->unique()->safeEmail(),
            'sandi_hash' => Hash::make(Str::password(24)),
            'status' => 'aktif',
        ];
    }

    public function denganSandi(string $sandi): self
    {
        return $this->state(['sandi_hash' => Hash::make($sandi)]);
    }

    public function nonaktif(): self
    {
        return $this->state(['status' => 'nonaktif']);
    }
}
