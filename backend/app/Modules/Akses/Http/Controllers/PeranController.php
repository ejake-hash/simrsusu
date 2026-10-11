<?php

namespace App\Modules\Akses\Http\Controllers;

use App\Core\Http\Paginasi;
use App\Modules\Akses\Http\Resources\PeranResource;
use App\Modules\Akses\Models\Peran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Master data peran (sub-menu master_data.peran). Pengelolaan lengkap di tiket 1.2c. */
final class PeranController
{
    public function index(Request $request): JsonResponse
    {
        return Paginasi::respons(Peran::query(), $request, 'dibuat_pada', arah: 'asc',
            bentuk: fn ($baris) => PeranResource::collection($baris)->resolve());
    }
}
