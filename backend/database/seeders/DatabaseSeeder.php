<?php

namespace Database\Seeders;

use App\Modules\Akses\Database\Seeders\AkunSintetisSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AkunSintetisSeeder::class);
    }
}
