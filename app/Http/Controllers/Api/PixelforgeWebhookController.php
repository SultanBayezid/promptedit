<?php

namespace App\Http\Controllers\Api;

use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * POST /api/webhooks/pixelforge
 *
 * This is the ONLY route exempt from CSRF (it lives in routes/api.php and
 * is authenticated by the Pixelforge HMAC signature on the raw body).
 * Signature verification, timestamp freshness, dedup and out-of-order
 * protection all happen in WebhookService.
 */
class PixelforgeWebhookController extends Controller
{
    public function __construct(protected readonly WebhookService $webhooks)
    {
    }

    public function __invoke(Request $request)
    {
        $result = $this->webhooks->handle(
            $request->getContent(),
            $request->header('Pixelforge-Signature'),
        );

        return response()->json(['received' => $result['status']], $result['http']);
    }
}
