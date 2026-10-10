<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Modules\Akses\Http\Resources\PenggunaResource;
use Illuminate\Http\Request;

final class SayaController
{
    public function __invoke(Request $request): PenggunaResource
    {
        return new PenggunaResource($request->user());
    }
}
