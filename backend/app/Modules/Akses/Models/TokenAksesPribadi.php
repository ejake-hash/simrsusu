<?php

namespace App\Modules\Akses\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken;

/** Token Sanctum berkunci UUID, hanya untuk integrasi mesin-ke-mesin (bukan login SPA). */
final class TokenAksesPribadi extends PersonalAccessToken
{
    use HasUuids;

    protected $table = 'sistem.personal_access_tokens';
}
