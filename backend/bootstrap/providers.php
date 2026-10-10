<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Modules\Registration\ModuleServiceProvider::class,
    App\Modules\Queue\ModuleServiceProvider::class,
    App\Modules\Emergency\ModuleServiceProvider::class,
];
