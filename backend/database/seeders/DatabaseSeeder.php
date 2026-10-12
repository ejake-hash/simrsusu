<?php

namespace Database\Seeders;

use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use App\Modules\MasterData\Database\Seeders\OrganisasiSintetisSeeder;
use App\Modules\Ttd\Database\Seeders\TtdSintetisSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AkunSintetisSeeder::class, OrganisasiSintetisSeeder::class, TtdSintetisSeeder::class]);
    }
}
