<?php

use App\Core\Http\SiapController;
use Illuminate\Support\Facades\Route;

// /up (hidup) didaftarkan di bootstrap/app.php. /siap tanpa autentikasi untuk penyeimbang beban,
// dengan batas laju per IP sendiri (PRD 14.1.1 butir 8).
Route::prefix('api/v1')->middleware('api')->group(function () {
    Route::get('siap', SiapController::class)
        ->withoutMiddleware('throttle:api')
        ->middleware('throttle:siap');
});
