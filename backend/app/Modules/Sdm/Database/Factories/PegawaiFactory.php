<?php

namespace App\Modules\Sdm\Database\Factories;

use App\Modules\Sdm\Models\Pegawai;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Data sintetis saja. @extends Factory<Pegawai> */
final class PegawaiFactory extends Factory
{
    protected $model = Pegawai::class;

    public function definition(): array
    {
        return [
            'nip' => 'SINTETIS-'.fake()->unique()->numerify('########'),
            'nama' => 'Pegawai Sintetis '.fake()->unique()->numerify('####'),
            'status' => 'aktif',
        ];
    }
}
