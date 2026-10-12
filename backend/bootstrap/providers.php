<?php

use App\Modules\Akses\ModuleServiceProvider as ModulAkses;
use App\Modules\Antrean\ModuleServiceProvider as ModulAntrean;
use App\Modules\Igd\ModuleServiceProvider as ModulIgd;
use App\Modules\MasterData\ModuleServiceProvider as ModulMasterData;
use App\Modules\Notifikasi\ModuleServiceProvider as ModulNotifikasi;
use App\Modules\Pendaftaran\ModuleServiceProvider as ModulPendaftaran;
use App\Modules\Sdm\ModuleServiceProvider as ModulSdm;
use App\Modules\Ttd\ModuleServiceProvider as ModulTtd;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    ModulSdm::class,
    ModulAkses::class,
    ModulMasterData::class,
    ModulNotifikasi::class,
    ModulTtd::class,
    ModulPendaftaran::class,
    ModulAntrean::class,
    ModulIgd::class,
    HorizonServiceProvider::class,
];
