<?php

use App\Modules\Akses\ModuleServiceProvider as ModulAkses;
use App\Modules\Emergency\ModuleServiceProvider as ModulEmergency;
use App\Modules\Queue\ModuleServiceProvider as ModulQueue;
use App\Modules\Registration\ModuleServiceProvider as ModulRegistration;
use App\Modules\Sdm\ModuleServiceProvider as ModulSdm;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ModulSdm::class,
    ModulAkses::class,
    ModulRegistration::class,
    ModulQueue::class,
    ModulEmergency::class,
];
