<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\EstimateService;
use App\Services\GenerationService;
use App\Services\PixelforgeException;
use App\Services\PricingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class GenerationController extends Controller
{
    use AuthorizesRequests;
    public function __construct(
        protected readonly GenerationService $service,
        protected readonly EstimateService $estimator,
        protected readonly PricingService $pricing,
    ) {
    }

    /** GET /generate — the generation form. */
    public function create(Request $request)
    {
        return Inertia::render('Generate', [
            'sizes' => config('pixelforge.sizes'),
            'qualities' => config('pixelforge.qualities'),
            'maxPromptChars' => (int) config('pixelforge.limits.max_prompt_chars'),
            'maxReferenceImages' => (int) config('pixelforge.limits.max_reference_images'),
            'markup' => (string) config('pixelforge.markup'),
            'balance' => $request->user()->balanceNano(),
            'recent' => $request->user()->generations()
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (Generation $g) => $g->toPageArray()),
        ]);
    }

    /** POST /generate/estimate — pre-submission cost estimate (no charge). */
    public function estimate(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:1', 'max:'.config('pixelforge.limits.max_prompt_chars')],
            'size' => ['required', 'string', 'in:'.implode(',', config('pixelforge.sizes'))],
            'quality' => ['required', 'string', 'in:'.implode(',', config('pixelforge.qualities'))],
            'reference_images' => ['nullable', 'array', 'max:'.config('pixelforge.limits.max_reference_images')],
            'reference_images.*' => ['url', 'starts_with:http://,https://'],
        ]);

        try {
            $estimate = $this->estimator->estimate(
                $validated['prompt'],
                $validated['size'],
                $validated['quality'],
                array_values($validated['reference_images'] ?? []),
            );
        } catch (PixelforgeException) {
            return response()->json([
                'error' => 'Pricing is temporarily unavailable from the provider. Please try again shortly.',
            ], 503);
        }

        return response()->json([
            'text_tokens' => $estimate['text_tokens'],
            'image_tokens' => $estimate['image_tokens'],
            'output_tokens' => $estimate['output_tokens'],
            'reasoning_tokens' => $estimate['reasoning_tokens'],
            'rates' => $estimate['rates'],
            'markup' => $estimate['markup'],
            'provider_cost_min_usd' => $estimate['provider_cost_min_usd'],
            'provider_cost_max_usd' => $estimate['provider_cost_max_usd'],
            'charge_min_usd' => $estimate['charge_min_usd'],
            'charge_max_usd' => $estimate['charge_max_usd'],
        ]);
    }

    /** POST /generate — submit a generation (creates a hold, queues the job). */
    public function store(Request $request)
    {
        $validated = $request->validate(GenerationService::rules());

        try {
            $generation = $this->service->submit($request->user(), $validated);
        } catch (\App\Services\InsufficientBalanceException $e) {
            return back()->withErrors([
                'prompt' => sprintf(
                    'Insufficient credits. This generation may cost up to $%s (held at submission). Your balance is $%s. Please top up.',
                    \App\Support\Money::toUsdString($e->requiredNano, 6),
                    \App\Support\Money::toUsdString($e->balanceNano, 6),
                ),
            ]);
        } catch (\App\Services\PixelforgeException $e) {
            return back()->withErrors([
                'prompt' => 'Could not get current pricing from the provider. Please try again shortly. (' . $e->getMessage() . ')',
            ]);
        }

        return redirect()->route('generations.show', $generation->uuid)
            ->with('message', 'Generation submitted. Tracking progress...');
    }

    /** GET /generations — the user's history (strict per-user isolation). */
    public function index(Request $request)
    {
        $query = $request->user()->generations()->latest('id');

        if ($status = $request->string('status')->toString() and in_array($status, ['queued', 'processing', 'succeeded', 'failed'], true)) {
            $query->where('status', $status);
        }
        if ($q = trim((string) $request->input('q'))) {
            $query->where('prompt', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%');
        }

        $generations = $query->paginate(10)->withQueryString();

        return Inertia::render('History', [
            'generations' => $generations->through(fn (Generation $g) => $g->toPageArray() + ['user_name' => $g->user?->name]),
            'filters' => ['status' => $status ?: '', 'q' => $q ?: ''],
            'stats' => [
                'total' => $request->user()->generations()->count(),
                'succeeded' => $request->user()->generations()->where('status', 'succeeded')->count(),
                'failed' => $request->user()->generations()->where('status', 'failed')->count(),
                'in_flight' => $request->user()->generations()->whereIn('status', ['queued', 'processing'])->count(),
            ],
        ]);
    }

    /** GET /generations/{uuid} — detail (owner or admin only). */
    public function show(Request $request, string $uuid)
    {
        $generation = Generation::query()
            ->where('uuid', $uuid)
            ->with('usageRecord')
            ->firstOrFail();

        $this->authorize('view', $generation);

        $data = $generation->toPageArray();
        $data['user_name'] = $generation->user?->name;
        $data['usage_record'] = $generation->usageRecord ? [
            'usage_date' => $generation->usageRecord->usage_date->toDateString(),
            'input_text_tokens' => $generation->usageRecord->input_text_tokens,
            'input_image_tokens' => $generation->usageRecord->input_image_tokens,
            'output_tokens' => $generation->usageRecord->output_tokens,
            'reasoning_tokens' => $generation->usageRecord->reasoning_tokens,
        ] : null;
        $data['ledger'] = \App\Models\FinancialTransaction::query()
            ->where('generation_id', $generation->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($t) => $t->toPageArray())
            ->values()
            ->all();

        return Inertia::render('Generations/Show', [
            'generation' => $data,
            'isAdmin' => (bool) $request->user()->is_admin,
        ]);
    }
}
