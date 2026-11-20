<?php

use App\Http\Controllers\Api\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pixelforge webhook intake
|--------------------------------------------------------------------------
| The ONLY unauthenticated route in the app: authenticated by the
| Pixelforge HMAC-SHA256 signature over the raw request body. API routes
| have no CSRF protection by design (none is needed — the signature IS
| the authentication).
*/

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.pixelforge');

