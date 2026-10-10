<?php

namespace App\Core\Http;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sama dengan middleware stateful Sanctum, tetapi cookie sesi tetap SameSite=Strict
 * (PRD 14.2). Sanctum bawaan menimpa session.same_site menjadi 'lax' di setiap permintaan SPA.
 */
final class SesiSpaStateful extends EnsureFrontendRequestsAreStateful
{
    protected function configureSecureCookieSessions()
    {
        config([
            'session.http_only' => true,
            'session.same_site' => 'strict',
        ]);
    }
}
